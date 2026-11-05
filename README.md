# Pro Backups

Plugin WordPress **100% gratuito** para criar e restaurar backups no formato `.wpress`
(o mesmo do All-in-One WP Migration), **sem limite de tamanho** e sem recursos pagos.

> Projeto independente. Não é afiliado à ServMask nem ao All-in-One WP Migration.
> A compatibilidade com o formato `.wpress` foi construída a partir do código GPLv3 do plugin gratuito.

Veja o [ROADMAP.md](ROADMAP.md) para o andamento.

## Desenvolvimento

```bash
tests/env/up.sh             # sobe 2 sites de teste (src :8091, dst :8092) + MariaDB, em Docker
tests/env/make-fixtures.sh  # gera .wpress reais com o AI1WM (BIG=1 gera também um de >2 GB)
```

## Licença

GPL-3.0-or-later. Veja [LICENSE](LICENSE).
