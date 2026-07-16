<?php

$caminhoArquivo = 'extrato_inter.csv';
$arquivoCidades = 'cidades.txt';

// 1. Garante que a lista de cidades exista localmente (Se não existir, baixa do IBGE)
garantirListaCidadesLocal($arquivoCidades);

if (($objetoArquivo = fopen($caminhoArquivo, 'r')) !== false) {
    
    // 2. Pula dinamicamente os metadados iniciais até achar o cabeçalho oficial do banco
    while (($linhaTexto = fgets($objetoArquivo)) !== false) {
        if (str_starts_with(trim($linhaTexto), 'Data Lançamento;')) {
            $headerLine = $linhaTexto;
            break;
        }
    }
    
    if (!isset($headerLine)) {
        die("Erro: Cabeçalho válido não encontrado no extrato.\n");
    }
    
    $header = str_getcsv($headerLine, ';');
    $header = array_map('trim', $header);
    
    // Carrega a lista de cidades na memória para busca rápida O(1)
    $cidadesValidas = carregarCidades($arquivoCidades);
    $transactions = [];

    // 3. Processa linha por linha do CSV
    while (($linhaTexto = fgets($objetoArquivo)) !== false) {
        $linhaTexto = trim($linhaTexto);
        if (empty($linhaTexto)) {
            continue;
        }

        $rowValues = str_getcsv($linhaTexto, ';');
        
        // Garante a integridade estrutural das colunas da linha
        if (count($header) !== count($rowValues)) {
            continue;
        }

        $row = array_combine($header, $rowValues);
        $descricaoBruta = trim($row['Descrição']);

        // Mapeia a transação e extrai o nome limpando a cauda de localização
        $metodoMapeado = mapearMetodo($linhaTexto);
        $contatoLimpo  = extrairNomeContatoComLista($descricaoBruta, $cidadesValidas);

        // FALLBACK: Se por algum motivo bizarro a string sumir por completo,
        // recupera a descrição bruta para não perder o registro financeiro
        if (strlen($contatoLimpo) < 2) {
            $contatoLimpo = $descricaoBruta;
        }

        // Tratamento do valor monetário padrão BR
        $rawAmount = str_replace(['.', ','], ['', '.'], $row['Valor']);
        $amount = (float) $rawAmount;

        // Monta o schema estruturado final
        $transactions[] = [
            'transaction_date'  => formatarData($row['Data Lançamento']),
            'counterparty_name' => $contatoLimpo,
            'amount'            => $amount,
            'type'              => $amount < 0 ? 'expense' : 'income',
            'method'            => $metodoMapeado
        ];
    }

    fclose($objetoArquivo);
    
    // Renderiza o array tratado no terminal
    print_r($transactions);
}

/**
 * Remove a cidade e o país focando exclusivamente nos últimos índices do texto.
 * Prioriza o casamento com a maior string de cidade possível para evitar falsos cortes em cidades compostas.
 */
function extrairNomeContatoComLista($description, $cidadesValidas) {
    // 1. Normalização inicial de espaços brancos
    $texto = str_replace("\t", "   ", $description);
    $texto = str_replace(["\xA0", "&nbsp;"], " ", $texto);
    $texto = preg_replace('/\s{2,}/', ' ', $texto); 
    $texto = trim($texto);

    // 2. Remove o "BRA" ou "BR" isolado no fim absoluto se existir
    $texto = preg_replace('/\b(BRA|BR)$/i', '', $texto);
    $texto = trim($texto);

    // Converte para o padrão de comparação (Maiúsculas e sem acento)
    $textoTratado = removerAcentos(mb_strtoupper($texto, 'UTF-8'));
    $palavrasOriginais = explode(' ', $texto);
    $palavrasTratadas = explode(' ', $textoTratado);

    $qtdPalavras = count($palavrasTratadas);

    if ($qtdPalavras > 1) {
        // Criamos dinamicamente um mapa de prefixos para busca instantânea O(1) por truncamento
        static $mapaPrefixos = null;
        if ($mapaPrefixos === null) {
            $mapaPrefixos = generarMapaPrefixos(array_keys($cidadesValidas));
        }

        $removeuLocalizacao = false;

        // ESTRATÉGIA 1: Testar as DUAS últimas palavras juntas (Casos de nomes compostos truncados: "CACHOEIRA GRA")
        if ($qtdPalavras >= 2) {
            $combinacaoDuasPalavras = $palavrasTratadas[$qtdPalavras - 2] . ' ' . $palavrasTratadas[$qtdPalavras - 1];
            $prefixoAlvo = mb_substr($combinacaoDuasPalavras, 0, 5);

            if (isset($mapaPrefixos[$prefixoAlvo])) {
                // Como o mapa está pré-ordenado por tamanho descritivo, ele testa a maior cidade primeiro
                foreach ($mapaPrefixos[$prefixoAlvo] as $cidadeCandidata) {
                    if (str_starts_with($cidadeCandidata, $combinacaoDuasPalavras)) {
                        unset($palavrasOriginais[$qtdPalavras - 1]);
                        unset($palavrasOriginais[$qtdPalavras - 2]);
                        $removeuLocalizacao = true;
                        break;
                    }
                }
            }
        }

        // ESTRATÉGIA 2: Se não removeu no composto, testa apenas a ÚLTIMA palavra isolada (Ex: "BRASIL", "BRASILIA")
        if (!$removeuLocalizacao) {
            $ultimaPalavra = $palavrasTratadas[$qtdPalavras - 1];
            
            // Match exato direto nas chaves da tabela hash
            if (isset($cidadesValidas[$ultimaPalavra])) {
                unset($palavrasOriginais[$qtdPalavras - 1]);
            } 
            // Match por truncamento simples da última palavra (mínimo de 5 letras para evitar falsos positivos)
            elseif (strlen($ultimaPalavra) >= 5) {
                $prefixoAlvo = mb_substr($ultimaPalavra, 0, 5);
                if (isset($mapaPrefixos[$prefixoAlvo])) {
                    foreach ($mapaPrefixos[$prefixoAlvo] as $cidadeCandidata) {
                        if (str_starts_with($cidadeCandidata, $ultimaPalavra)) {
                            unset($palavrasOriginais[$qtdPalavras - 1]);
                            break;
                        }
                    }
                }
            }
        }

        $texto = implode(' ', $palavrasOriginais);
    }

    return trim($texto, " \t\n\r\0\x0B*-,.");
}

/**
 * Agrupa as cidades pelas suas primeiras 5 letras e ordena por tamanho de string decrescente.
 * Permite buscar matches parciais de cidades grandes sem varrer 5k linhas de loops a cada iteração.
 */
function generarMapaPrefixos($listaCidades) {
    $mapa = [];
    
    // Força as strings mais longas (Ex: CACHOEIRA GRANDE) a ficarem no topo da lista de busca
    usort($listaCidades, function($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    foreach ($listaCidades as $cidade) {
        if (strlen($cidade) >= 5) {
            $prefixo = mb_substr($cidade, 0, 5);
            $mapa[$prefixo][] = $cidade;
        }
    }
    return $mapa;
}

/**
 * Remove acentos e caracteres especiais latinos para normalização estrita
 */
function removerAcentos($string) {
    return preg_replace(
        ['/[ÁÀÂÃÄáàâãä]/u', '/[ÉÈÊËéèêë]/u', '/[ÍÌÎÏíìîï]/u', '/[ÓÒÔÕÖóòôõö]/u', '/[ÚÙÛÜúùûü]/u', '/[Çç]/u'],
        ['A', 'E', 'I', 'O', 'U', 'C'],
        $string
    );
}

/**
 * Consome a API estruturada do IBGE e gera um arquivo de dicionário limpo local por segurança e performance
 */
function garantirListaCidadesLocal($arquivoDestino) {
    if (file_exists($arquivoDestino)) {
        return;
    }

    echo "Aguarde: Baixando lista oficial de municípios do IBGE para o arquivo local...\n";
    $url = "https://servicodados.ibge.gov.br/api/v1/localidades/municipios";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resposta = curl_exec($ch);
    curl_close($ch);

    if (!$resposta) {
        die("Erro fatal: Não foi possível obter os dados do IBGE. Verifique sua conexão de rede.\n");
    }

    $municipios = json_decode($resposta, true);
    if (!is_array($municipios)) {
        die("Erro fatal: O formato de payload JSON retornado pela API do IBGE é inválido.\n");
    }

    $cidadesLimpas = [];
    foreach ($municipios as $mun) {
        if (isset($mun['nome'])) {
            $nomeCidade = mb_strtoupper($mun['nome'], 'UTF-8');
            $nomeCidade = removerAcentos($nomeCidade);
            // Normaliza hífens e apóstrofos (Ex: D'OESTE -> D OESTE)
            $nomeCidade = preg_replace('/[^A-Z0-9 ]/', ' ', $nomeCidade);
            $nomeCidade = preg_replace('/\s{2,}/', ' ', $nomeCidade);
            $cidadesLimpas[] = trim($nomeCidade);
        }
    }

    $cidadesLimpas = array_unique($cidadesLimpas);
    sort($cidadesLimpas);

    file_put_contents($arquivoDestino, implode("\n", $cidadesLimpas));
    echo "Lista de cidades gerada com sucesso em: {$arquivoDestino}\n\n";
}

/**
 * Carrega o arquivo local plano para uma tabela Hash na memória
 */
function carregarCidades($caminho) {
    $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $estruturaBusca = [];
    foreach ($linhas as $linha) {
        $estruturaBusca[trim($linha)] = true;
    }
    return $estruturaBusca;
}

/**
 * Converte datas brasileiras DD/MM/AAAA para formato internacional SQL ISO
 */
function formatarData($date) {
    [$day, $month, $year] = array_map('trim', explode('/', trim($date)));
    return "{$year}-{$month}-{$day}";
}

/**
 * Mapeia heurísticas do texto bruto da linha para deduzir o canal financeiro utilizado
 */
function mapearMetodo($textoCompletoLinha) {
    if (preg_match('/(débito|debito|cartao|cartão|compra|pag\*|picpay\*|mercadopago)/i', $textoCompletoLinha)) {
        return 'card';
    }
    if (stripos($textoCompletoLinha, 'pix') !== false) return 'pix';
    if (stripos($textoCompletoLinha, 'ted') !== false) return 'ted';
    if (stripos($textoCompletoLinha, 'doc') !== false) return 'doc';
    if (stripos($textoCompletoLinha, 'boleto') !== false) return 'boleto';
    
    if (preg_match('/(tarifa|cesta|iof|juros|manutencao)/i', $textoCompletoLinha)) {
        return 'other'; 
    }
    return 'unknown';
}