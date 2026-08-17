# test

Egyetlen PHP oldal, amely Veresegyházra kiszámolja a Nap és a Hold pillanatnyi
horizontális koordinátáit, és ebből egy teljes képernyős inline SVG égboltot renderel.

## Futtatókörnyezet

| Réteg | Érték |
|---|---|
| OS | Ubuntu 26.04 LTS (UpCloud VPS) |
| Webszerver | Apache 2.4 (`mod_ssl`, HTTP/2, `mod_proxy_fcgi`) |
| PHP | 8.5 FPM |
| TLS | Let's Encrypt (certbot), automatikus megújítás |
| Alkalmazás gyökér | `/srv/test` — ez a repo klónja |
| DocumentRoot | `/srv/test/public` |

Adatbázis nincs, Composer-függőség nincs, külső API nincs.

## Élő környezet

- <https://test.ma.harmony-solutions.hu>
- Health-check: <https://test.ma.harmony-solutions.hu/health.php>

## Deploy és rollback

A szerveren, `deploy` felhasználóval:

```bash
test-deploy                 # a main ág legutolsó commitjára frissít
test-rollback               # egy lépés vissza az előző kiadásra
test-rollback <commit-sha>  # adott commitra
```

Részletek: `Projects/test/Resources/plan/deploy.md` a projekt munkaterületén.

## Fejlesztés

A fejlesztés a VPS-en történik, nem lokálisan. Branch minta: `feat/TASK-00xx-<slug>`,
a `main` védett, minden változás PR-en keresztül megy.
