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

### Fase 2 — Pipeline de restore
- [ ] Portar os passos validate → clean, mantendo a máquina de estados por AJAX + status por polling.
- [ ] Banco (parte mais delicada): troca de URLs/caminhos inclusive em dados serializados, prefixo de tabelas,
      MySQL/MariaDB/SQLite (portar `Ai1wm_Database*`).
- [ ] Capability `manage_options`, nonce e secret key; pasta de storage protegida
      (`.htaccess`, `index.php`, `web.config`).

### Fase 3 — Sem limite de tamanho (o recurso principal)
- [ ] Uploader JS em chunks de 5 MB, com retentativas e retomada; checagem de espaço em disco.
- [ ] **Restaurar arquivo já no servidor:** o usuário envia o `.wpress` por FTP para
      `wp-content/pro-backups/` e clica em "Restaurar", sem passar por nenhum limite de upload.

### Fase 4 — Página de backups e WP-CLI
- [ ] Listar / restaurar / baixar / excluir.
- [ ] `wp pro-backups restore <arquivo>` (recurso pago no AI1WM).

### Fase 5 — Export (opcional)
- [ ] Gerar `.wpress` compatível com o AI1WM.
- [ ] Exclusões: arquivos, tabelas, cache, plugins/temas inativos (telas da Unlimited em `view/export/option`).

### Fase 6 — Extras da Unlimited (depois)
- [ ] Agendamentos (WP-Cron), retenção, ferramentas de reset.

### Fase 7 — Qualidade e lançamento
- [ ] Matriz PHP 7.4–8.4, multisite, hosts com `max_execution_time` de 30 s.
- [ ] Publicação no wordpress.org (sem trialware; sendo 100% free não há problema).

## Não verificado ainda
- Detalhes dos passos de banco (`class-ai1wm-import-database.php`) e do bloco EOF v2.
- Auditoria completa de malware no zip crackeado.
- Estimativas de prazo são grosseiras.
