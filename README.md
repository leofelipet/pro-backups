# Pro Backups

Plugin WordPress **100% gratuito** para criar e restaurar backups no formato `.wpress`
(o mesmo do All-in-One WP Migration), **sem limite de tamanho** e sem recursos pagos.

> Projeto independente. Não é afiliado à ServMask nem ao All-in-One WP Migration.
> A compatibilidade com o formato `.wpress` foi construída a partir do código GPLv3 do plugin gratuito.

## O que faz

- **Restaura `.wpress` de qualquer tamanho**: upload em partes (ignora `upload_max_filesize`), retomável; ou envie por FTP para `wp-content/pro-backups/`.
- Restaura backups do **All-in-One WP Migration** (inclusive gzip/bzip2 e criptografados); o AI1WM restaura os backups do Pro Backups.
- Troca de URL/caminhos/e-mails, inclusive em dados serializados; MySQL, MariaDB e SQLite.
- Criação de backups com exclusões, gzip/bzip2, AES-256.
- Agendamentos (WP-Cron), retenção, ferramentas de reset (sempre com backup antes).
- WP-CLI: `wp pro-backups backup|restore|list|delete`.

Detalhes e decisões: [ROADMAP.md](ROADMAP.md). Texto para o wordpress.org: [readme.txt](readme.txt).

## Desenvolvimento

```bash
composer install && vendor/bin/phpunit       # testes unitários (fixtures reais do AI1WM em tests/fixtures)
tests/env/up.sh                              # 2 sites de teste (src :8091, dst :8092) + MariaDB, em Docker
tests/env/make-fixtures.sh                   # gera .wpress com o AI1WM real (BIG=1: também um de >2 GB)
tests/integration/restore.sh                 # AI1WM -> Pro Backups via AJAX (sem/com senha, gzip, erro de bz2)
tests/integration/export.sh                  # Pro Backups -> Pro Backups e -> AI1WM
tests/integration/cli-and-files.sh           # WP-CLI, download/exclusão autenticados
tests/integration/schedules-reset.sh         # cron, retenção, reset + desfazer
tests/integration/stress.sh                  # orçamento de 0,1 s por requisição
tests/integration/big.sh                     # backup de 2,2 GB por HTTP
tests/integration/ui-restore.sh / ui-export.sh  # navegador (playwright-cli)
tests/integration/sqlite.sh / multisite.sh   # (alteram o site dst: recrie o ambiente depois)
tests/matrix.sh php74 php84 mysql84          # suíte de integração em outras versões de PHP/banco
bin/build-zip.sh                             # dist/pro-backups-<versão>.zip
```

## Licença

GPL-3.0-or-later. Veja [LICENSE](LICENSE).
