# Roadmap — Pro Backups (restaurador .wpress 100% free)

## ⚠️ Aviso sobre `docs/`

O zip `all-in-one-wp-migration-unlimited-extension.zip` **está crackeado (nulled)**. O arquivo principal
(`all-in-one-wp-migration-unlimited-extension.php`, ~linhas 75–95) tem código injetado que:

- falsifica a checagem de licença em `servmask.com/purchase/activations` (`pre_http_request`);
- substitui o `fetch` do `service.wasm` por um módulo vazio no navegador;
- grava dados falsos em `ai1wm_updater`.

Não reaproveitar código dele e não commitá-lo. O plugin base (v7.113) é **GPLv3**: pode ser reaproveitado
mantendo GPLv3 + avisos de copyright, com prefixo próprio e **sem** a marca "All-in-One"/ServMask.

## O que a análise mostrou

### Por que o free trava em backups grandes
- O limite é só `wp_max_upload_size()` checado no JS (`import.min.js`); acima disso aparece o aviso "pro".
- O upload é um único POST, então bate em `upload_max_filesize` / `post_max_size`.
- A Unlimited troca o passo `ai1wm_import` de prioridade 5 por **upload em chunks**
  (param `upload_offset`, 5 MB — `AI1WM_MAX_CHUNK_SIZE`). O resto do restore é praticamente o do free.

### Formato .wpress (`class-ai1wm-archiver.php`)
- Para cada arquivo: header de 4377 bytes = nome (255) + tamanho (14) + mtime (12) + caminho (4088) + crc32 (8),
  seguido do conteúdo.
- Termina com um bloco EOF (a v2 traz o CRC do arquivo inteiro).
- Conteúdo opcionalmente comprimido (gzip/bzip2) e criptografado por chunk via openssl;
  chave = `sha1(senha)` truncada ao tamanho do IV (`ai1wm_decrypt_string`).
- Dentro: `package.json`, `database.sql`, `multisite.json` e arquivos do `wp-content`.

### Pipeline de restore (filtro `ai1wm_import`, por prioridade)
upload(5) → compatibility(10) → validate(50) → crc(55) → compression(70) → encryption(75) →
confirm(100) → blogs(150) → permalinks(170) → enumerate(200) → content(250) → mu-plugins(270) →
db-file(295) → database(300) → users(310) → options(330) → done(350) → clean(400)

Cada request AJAX processa um pedaço curto, devolve os params e continua. Por isso funciona com backups de
vários GB sem timeout.

## Decisão de arquitetura

| Opção | Prós | Contras |
|---|---|---|
| A. Addon em cima do AI1WM free (como a Unlimited) | ~300 linhas, rápido | Depende da ServMask; um update deles pode quebrar |
| **B. Plugin independente (fork do motor de restore, prefixo próprio)** | Sem dependência, 100% seu | Mais trabalho |

**Escolha recomendada: B**, começando só pelo restore. A opção A serve como protótipo de 1 dia.

## Fases

### Fase 0 — Base (1–2 dias) ✅
- [x] Definir nome, slug e prefixo (`pro-backups` / `pbk_` / classes `PBK_`), licença GPLv3.
- [x] Remover os zips do repositório (`.gitignore`).
- [x] Juntar `.wpress` reais de teste: pequeno, >2 GB, criptografado, gzip, bzip2, multisite.
      Gerados com o AI1WM real por `tests/env/make-fixtures.sh` (pequenos em `tests/fixtures/`,
      completos em `tests/fixtures/large/`, >2 GB com `BIG=1`). Multisite: o AI1WM grátis não exporta rede
      (exige extensão paga), então não há fixture real; o plugin detecta `multisite.json` e recusa com mensagem clara.

### Fase 1 — Leitor .wpress (núcleo) ✅
- [x] Listar, extrair em streaming e retomar por offset (`includes/class-pbk-archive.php`, `PBK_Archive_Reader`).
- [x] Validação de CRC (EOF v2, retomável via `crc32_combine`), descompressão gzip/bzip2, descriptografia AES-256-CBC.
- [x] **Segurança:** bloquear caminhos com `../` e absolutos dentro do arquivo (path traversal) — `PBK_Archive::safe_path()`.
- [x] Testes PHPUnit com os backups da Fase 0 (`tests/unit/ArchiveReaderTest.php`; rodar com `vendor/bin/phpunit`).

### Fase 2 — Pipeline de restore ✅
- [x] Passos validate → crc → password → confirm → extract (arquivos + database.sql numa só passada) → database → finalize
      (`includes/class-pbk-importer.php`), máquina de estados em arquivo (`PBK_Job`) avançada por AJAX (`pbk_run`),
      cada resposta devolve o status (o polling é a própria chamada `pbk_run`).
- [x] Banco: troca de URLs/caminhos/e-mails inclusive em dados serializados (`PBK_Replace`), prefixo de tabelas
      (`SERVMASK_PREFIX_`), fallback de collation (MySQL 8 ↔ MariaDB), MySQL/MariaDB via mysqli e SQLite via `$wpdb`
      (matriz de bancos testada na Fase 7). Durante o restore só este plugin fica ativo e o tema não é carregado.
- [x] `manage_options` + nonce para iniciar; token aleatório por job para continuar (sobrevive à troca do banco);
      pasta de storage protegida (`.htaccess`, `index.php`, `web.config`); arquivos de job com guarda `<?php exit; ?>`.
- Teste de ponta a ponta: `tests/integration/restore.sh` (backups reais do AI1WM, sem/ com criptografia, gzip, erro de bz2).

### Fase 3 — Sem limite de tamanho (o recurso principal) ✅
- [x] Uploader JS em chunks (até 5 MB, ajustado ao `upload_max_filesize` do servidor), com retentativas, backoff e
      ressincronização de offset; checagem de espaço em disco (`includes/class-pbk-upload.php`, `assets/admin.js`).
- [x] **Restaurar arquivo já no servidor:** qualquer `.wpress` enviado por FTP para `wp-content/pro-backups/`
      (ou já existente em `wp-content/ai1wm-backups/`) aparece na lista com botão "Restore".
- Testes: `tests/integration/upload.php` (retomada/duplicado/lacuna) e `tests/integration/ui-restore.sh`
  (navegador: 15 MB enviados com `upload_max_filesize=2M`, senha, confirmação, restore completo).

### Fase 4 — Página de backups e WP-CLI ✅
- [x] Listar / restaurar / baixar / excluir (download autenticado via `admin-post.php` com nonce; storage não é público).
- [x] `wp pro-backups restore <arquivo|caminho> [--password=] [--yes]`, `wp pro-backups list`, `wp pro-backups delete`.
- Teste: `tests/integration/cli-and-files.sh`.

### Fase 5 — Export ✅
- [x] Gerar `.wpress` compatível com o AI1WM (`PBK_Archive_Writer`, `PBK_Sql_Exporter`, `PBK_Exporter`): retomável,
      gzip/bzip2, AES-256, EOF v2 com CRC. **Verificado: o próprio AI1WM restaura os backups do Pro Backups.**
- [x] Exclusões: mídia, plugins, temas, plugins/temas inativos, cache, banco, comentários spam, revisões,
      arquivos/pastas e tabelas específicas (aba "Create backup" e `wp pro-backups backup --exclude=...`).
- Testes: `tests/unit/ArchiveWriterTest.php`, `tests/integration/export.sh` (Pro Backups ↔ AI1WM), `tests/integration/ui-export.sh`.

### Fase 6 — Extras da Unlimited ✅
- [x] Agendamentos (WP-Cron): horária, 2×/dia, diária, semanal, mensal, com horário inicial; backups grandes continuam
      em eventos `pbk_job_continue`; "Run now"; eventos re-registrados após um restore (`includes/class-pbk-schedules.php`).
- [x] Retenção: manter N backups, apagar mais antigos que X dias, limite de tamanho total (aba Settings).
- [x] Ferramentas de reset: mídia, plugins, temas (mantém o ativo), banco (instalação limpa mantendo login/URL/título).
      **Sempre cria um backup completo antes**; exige digitar RESET (`includes/class-pbk-reset.php`).
- Teste: `tests/integration/schedules-reset.sh` (cron + retenção + reset via web + desfazer restaurando o backup de segurança).

### Fase 7 — Qualidade e lançamento ✅
- [x] Matriz PHP 7.4–8.4, multisite, hosts com `max_execution_time` de 30 s.
      `tests/matrix.sh`: PHP 7.4 (WP 6.1), 8.3, 8.4 com MariaDB 11 e PHP 8.3 com MySQL 8.4 — suíte de integração
      completa verde em todos. SQLite (`sqlite.sh`), multisite recusado sem alterar nada (`multisite.sh`),
      orçamento de 0,1 s por requisição (`stress.sh`; achou e corrigiu o bug da tabela `options` dividida entre
      requisições — agora importada de forma atômica), backup de 2,2 GB por HTTP (`big.sh`). CI no GitHub Actions.
- [x] Publicação no wordpress.org (sem trialware; sendo 100% free não há problema): pacote pronto —
      `readme.txt` no formato oficial, `uninstall.php`, `languages/pro-backups.pot`, **Plugin Check sem erros**,
      zip em `bin/build-zip.sh` (tag/release no GitHub ainda não criados). A submissão em wordpress.org/plugins/developers/add/ precisa
      ser feita pelo dono da conta wordpress.org (revisão manual da equipe de plugins).

## Limitações conhecidas
- Backups de rede multisite e restore em multisite: recusados com mensagem clara (o AI1WM grátis também não exporta rede).
- Enumeração de arquivos do backup roda numa única requisição (rápida, sem ler conteúdo); sites com centenas de
  milhares de arquivos em hosts muito lentos podem precisar torná-la retomável (marcado com `shortcut:` no código).
- Em nginx, a pasta `wp-content/pro-backups/` precisa de uma regra `deny` (o `.htaccess` não vale lá); os arquivos
  de job já são `.php` com `exit`, mas os `.wpress` dependem dessa regra ou do nome aleatório.
- Se uma requisição morrer (fatal/timeout do servidor) no meio do banco, o restore não é desfeito automaticamente:
  restaure novamente o mesmo backup ou o backup anterior.
