<?php
declare(strict_types=1);

/**
 * Envio de e-mail por SMTP autenticado (a Hostinger recusa o mail() do PHP).
 * Os dados da caixa ficam em data/smtp.json (fora do git e fora do zip, bloqueado pelo .htaccess):
 * {"host": "smtp.hostinger.com", "porta": 465, "usuario": "noreply@seudominio.com", "senha": "..."}
 */

/** Dados do SMTP, ou null quando data/smtp.json não existe ou está incompleto (aí o site usa o mail() do PHP). */
function config_smtp(): ?array
{
    $c = json_decode((string) @file_get_contents(RAIZ . '/data/smtp.json'), true);
    if (!is_array($c) || empty($c['host']) || empty($c['usuario']) || empty($c['senha'])
        || !filter_var($c['usuario'], FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return ['host' => (string) $c['host'], 'porta' => (int) ($c['porta'] ?? 465), 'usuario' => (string) $c['usuario'], 'senha' => (string) $c['senha']];
}

/**
 * Envia uma mensagem de texto. $corpo já vem em base64 (como no contato.php).
 * Porta 465 = SSL direto; 587 = STARTTLS; outra porta = sem criptografia (só para testes locais).
 * Nunca lança erro nem escreve a senha no log: devolve false e anota o motivo.
 */
function enviar_smtp(array $c, string $para, string $assunto, string $corpo, array $cabecalhosExtras = []): bool
{
    $fp = null;
    $falhar = static function (string $motivo) use (&$fp): bool {
        error_log('[site] SMTP: ' . $motivo);
        if (is_resource($fp)) {
            @fclose($fp);
        }
        return false;
    };
    $ler = static function () use (&$fp): string {
        $resp = '';
        while (is_resource($fp) && ($linha = fgets($fp, 1024)) !== false) {
            $resp .= $linha;
            if (strlen($linha) < 4 || $linha[3] !== '-') { // linha final da resposta: "250 texto"
                break;
            }
        }
        return $resp;
    };
    $cmd = static function (string $texto, string $esperado) use (&$fp, $ler): ?string {
        if ($texto !== '') {
            fwrite($fp, $texto . "\r\n");
        }
        $resp = $ler();
        return str_starts_with($resp, $esperado) ? $resp : null;
    };

    $fp = @stream_socket_client(($c['porta'] === 465 ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['porta'], $no, $msg, 10);
    if (!$fp) {
        return $falhar("não conectou a {$c['host']}:{$c['porta']} ($msg)");
    }
    stream_set_timeout($fp, 10);
    $nome = preg_replace('/[^a-z0-9.-]/i', '', (string) parse_url('//' . substr(strrchr($c['usuario'], '@'), 1), PHP_URL_HOST)) ?: 'localhost';

    if ($cmd('', '220') === null || $cmd("EHLO $nome", '250') === null) {
        return $falhar('sem resposta inicial do servidor');
    }
    if ($c['porta'] === 587) {
        if ($cmd('STARTTLS', '220') === null || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
            || $cmd("EHLO $nome", '250') === null) {
            return $falhar('STARTTLS falhou');
        }
    }
    if ($cmd('AUTH LOGIN', '334') === null || $cmd(base64_encode($c['usuario']), '334') === null
        || $cmd(base64_encode($c['senha']), '235') === null) {
        return $falhar('usuário ou senha recusados');
    }
    if ($cmd('MAIL FROM:<' . $c['usuario'] . '>', '250') === null || ($cmd('RCPT TO:<' . $para . '>', '25') === null)) {
        return $falhar('remetente ou destinatário recusado');
    }
    if ($cmd('DATA', '354') === null) {
        return $falhar('DATA recusado');
    }
    $cabecalhos = array_merge([
        'Date: ' . date('r'),
        'From: Site <' . $c['usuario'] . '>',
        'To: <' . $para . '>',
        'Subject: ' . $assunto,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $nome . '>',
    ], $cabecalhosExtras);
    // O corpo é base64 (sem linhas começando em ponto), então não precisa do "dot-stuffing"
    fwrite($fp, implode("\r\n", $cabecalhos) . "\r\n\r\n" . $corpo . "\r\n.\r\n");
    if (!str_starts_with($ler(), '250')) {
        return $falhar('mensagem recusada');
    }
    $cmd('QUIT', '221');
    @fclose($fp);
    return true;
}
