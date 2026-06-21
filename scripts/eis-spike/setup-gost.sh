#!/usr/bin/env bash
#
# setup-gost.sh — ставит ГОСТ-TLS на ВРЕМЕННЫЙ RU-VPS (Debian/Ubuntu) для смоук-теста ЕИС.
#
# ЭТО НЕ ДЛЯ ПРОДА. Скрипт пачкает системный openssl gost-движком. Сценарий:
#   поднял дешёвый RU-VPS → прогнал этот скрипт → прогнал probe.sh → ВЫКИНУЛ VPS.
#
# После успешного прогона системный openssl/curl умеют ГОСТ-шифры через
# сгенерированный конфиг (по умолчанию /etc/ssl/gost.cnf). probe.sh и
# `php artisan eis:probe` подхватывают его через OPENSSL_CONF.
#
# Использует gost-engine (бесплатный движок OpenSSL) — КриптоПро НЕ нужен,
# поэтому истёкший триал КриптоПро не блокер для проверки транспорта.
#
# Использование:
#   sudo bash setup-gost.sh
#   sudo GOST_CNF=/custom/path.cnf bash setup-gost.sh   # переопределить путь конфига
#
set -euo pipefail

GOST_CNF="${GOST_CNF:-/etc/ssl/gost.cnf}"

log()  { printf '\033[1;36m[gost-setup]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[gost-setup]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[gost-setup] ОШИБКА:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "Запусти через sudo/root — нужен apt и запись в /etc/ssl."
command -v openssl >/dev/null 2>&1 || die "openssl не найден."
command -v apt-get >/dev/null 2>&1 || die "apt-get не найден — скрипт рассчитан на Debian/Ubuntu."

# --- 1. Установка gost-engine ------------------------------------------------
log "apt-get update…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

log "Ставлю gost-engine…"
# В bookworm/jammy+ пакет называется gost-engine; в старых — libengine-gost-openssl1.1.
if ! apt-get install -y -qq gost-engine 2>/dev/null; then
    warn "Пакет gost-engine недоступен, пробую libengine-gost-openssl1.1…"
    apt-get install -y -qq libengine-gost-openssl1.1 \
        || die "Не удалось поставить gost-engine ни одним именем пакета."
fi

# --- 2. Поиск gost.so и каталога движков ------------------------------------
ENGINESDIR="$(openssl version -e 2>/dev/null | sed -E 's/ENGINESDIR: *"?([^"]*)"?/\1/' || true)"
[ -n "${ENGINESDIR:-}" ] || die "Не определил ENGINESDIR (openssl version -e)."

GOST_SO=""
for cand in "$ENGINESDIR/gost.so" \
            /usr/lib/*/engines-3/gost.so \
            /usr/lib/*/engines-1.1/gost.so \
            /usr/lib/engines-3/gost.so; do
    if [ -f "$cand" ]; then GOST_SO="$cand"; break; fi
done
[ -n "$GOST_SO" ] || die "gost.so не найден после установки (искал в $ENGINESDIR и /usr/lib/*/engines-*)."
log "Движок: $GOST_SO"

OSSL_MAJOR="$(openssl version | sed -E 's/^OpenSSL ([0-9]+).*/\1/')"
log "OpenSSL major: $OSSL_MAJOR"

# ⚠️ ИЗВЕСТНЫЙ ТУПИК (проверено 2026-06-20 на dev-боксе OpenSSL 3.0.2):
# ГОСТ-TLS-ШИФРОНАБОРЫ через gost-engine на OpenSSL 3.x НЕ поддерживаются — ENGINE-API
# в 3.0 устарел, движок грузит крипто-примитивы, но TLS-handshake с ГОСТ-cipher не
# поднимается. Крипто-примитивы загрузятся (проверка ниже пройдёт), но probe.sh почти
# наверняка не получит ГОСТ-cipher на STAGE 0. РАБОЧИЕ пути для спайка:
#   1) Взять VPS на OpenSSL 1.1.1 — Debian 11 (bullseye) или Ubuntu 20.04 — там
#      gost-engine ГОСТ-TLS работает в полном объёме (этот бесплатный путь ещё НЕ
#      проверялся, ради него спайк и затевается).
#   2) stunnel + КриптоПро CSP (нужна ЛИЦЕНЗИЯ, триал истёк) — платный путь.
if [ "$OSSL_MAJOR" -ge 3 ]; then
    warn "ВНИМАНИЕ: OpenSSL ${OSSL_MAJOR}.x — ГОСТ-TLS через gost-engine тут, скорее всего, НЕ взлетит (известный тупик)."
    warn "Для реальной проверки бери VPS на OpenSSL 1.1.1 (Debian 11 / Ubuntu 20.04). Продолжаю — крипто-примитивы загружу, но STAGE 0 в probe.sh, вероятно, не даст ГОСТ-cipher."
fi

# --- 3. Генерация самодостаточного конфига ----------------------------------
# Не .include системного cnf — во избежание конфликта дублирующихся [openssl_init].
# Активируем default-провайдер (чтобы обычный RSA/TLS не сломался) + gost-движок.
log "Пишу конфиг $GOST_CNF…"
if [ "$OSSL_MAJOR" -ge 3 ]; then
    cat > "$GOST_CNF" <<EOF
# Сгенерировано setup-gost.sh — ГОСТ-движок для openssl 3.x. ТОЛЬКО для смоук-теста.
openssl_conf = openssl_init

[openssl_init]
providers = provider_sect
engines = engine_sect

[provider_sect]
default = default_sect

[default_sect]
activate = 1

[engine_sect]
gost = gost_conf

[gost_conf]
engine_id = gost
dynamic_path = $GOST_SO
default_algorithms = ALL
EOF
else
    cat > "$GOST_CNF" <<EOF
# Сгенерировано setup-gost.sh — ГОСТ-движок для openssl 1.1. ТОЛЬКО для смоук-теста.
openssl_conf = openssl_init

[openssl_init]
engines = engine_sect

[engine_sect]
gost = gost_conf

[gost_conf]
engine_id = gost
dynamic_path = $GOST_SO
default_algorithms = ALL
EOF
fi

# --- 4. Проверка, что движок грузится ---------------------------------------
log "Проверяю загрузку движка…"
# `openssl engine gost -t` печатает «(gost) ...» и «[ available ]» на РАЗНЫХ строках,
# поэтому матчим по маркеру [ available ], а не по строчному '(gost).*available'
# (который никогда не совпадёт через перенос строки и ложно ронял бы скрипт).
if OPENSSL_CONF="$GOST_CNF" openssl engine gost -t 2>&1 | grep -q '\[ available \]'; then
    log "✅ Движок gost загружается."
else
    OPENSSL_CONF="$GOST_CNF" openssl engine gost -t 2>&1 | sed 's/^/    /' || true
    die "Движок gost НЕ загрузился — проверь dynamic_path и версию пакета."
fi

if OPENSSL_CONF="$GOST_CNF" openssl ciphers 2>/dev/null | tr ':' '\n' | grep -qi gost; then
    log "✅ ГОСТ-шифры доступны в наборе."
else
    warn "ГОСТ-шифры не видны в наборе openssl ciphers — для TLS1.2 это бывает нормой (шифры подставляет движок при handshake). probe.sh покажет реальный результат."
fi

cat <<EOF

────────────────────────────────────────────────────────────────────────
ГОТОВО. Дальше:

  export OPENSSL_CONF=$GOST_CNF
  bash $(dirname "$0")/probe.sh

probe.sh подхватит OPENSSL_CONF сам, если не задан.

Замечание про CA: даже при успешном ГОСТ-handshake проверка серверного
сертификата ЕИС требует «Корневой сертификат Минцифры (ГОСТ)». probe.sh
сначала бьёт с -k (доказать транспорт+токен), потом с проверкой CA.
────────────────────────────────────────────────────────────────────────
EOF
