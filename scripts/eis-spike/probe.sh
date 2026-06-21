#!/usr/bin/env bash
#
# probe.sh — ОДИН реальный запрос к боевому ЕИС, staged-диагностика.
#
# Запускать на RU-VPS ПОСЛЕ setup-gost.sh. Цель — до любых вложений в прод-инфру
# доказать, что запрос возвращает данные, а не «гасится», и если гасится — на каком
# именно шаге (транспорт / токен / форма конверта / CA).
#
# Три стадии, эскалация от «дёшево и грубо» к «как в проде»:
#   STAGE 0  openssl s_client  — прошёл ли вообще ГОСТ-handshake, какой шифр.
#   STAGE 1  curl -k (insecure) — транспорт + токен + форма SOAP-конверта без возни с CA.
#   STAGE 2  curl с проверкой CA — полный безопасный путь (нужен корневой CA Минцифры).
#
# Переменные окружения (все опциональны):
#   OPENSSL_CONF  путь к ГОСТ-конфигу (по умолчанию /etc/ssl/gost.cnf от setup-gost.sh)
#   EIS_ENDPOINT  переопределить endpoint (иначе берётся из .env репозитория)
#   EIS_TOKEN     переопределить токен   (иначе берётся из .env)
#   REESTR        если задан — лёгкий вызов getDocsByReestrNumber(<номер>)
#   REGION        регион для getDocsByOrgRegion (по умолчанию 77)
#   SUBSYSTEM     подсистема: PRIZ=44-ФЗ, RI223=223-ФЗ (по умолчанию PRIZ)
#   DATE          дата YYYY-MM-DD (по умолчанию вчера)
#
set -uo pipefail   # без -e: стадии должны доходить до конца и печатать диагноз

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$REPO_ROOT/.env"

c_inf='\033[1;36m'; c_ok='\033[1;32m'; c_warn='\033[1;33m'; c_err='\033[1;31m'; c_end='\033[0m'
hdr()  { printf "\n${c_inf}━━━ %s ━━━${c_end}\n" "$*"; }
ok()   { printf "${c_ok}✅ %s${c_end}\n" "$*"; }
warn() { printf "${c_warn}⚠️  %s${c_end}\n" "$*"; }
err()  { printf "${c_err}❌ %s${c_end}\n" "$*"; }

# --- Конфиг ------------------------------------------------------------------
if [ -z "${OPENSSL_CONF:-}" ] && [ -f /etc/ssl/gost.cnf ]; then
    export OPENSSL_CONF=/etc/ssl/gost.cnf
fi
[ -n "${OPENSSL_CONF:-}" ] && printf "OPENSSL_CONF=%s\n" "$OPENSSL_CONF" || warn "OPENSSL_CONF не задан — ГОСТ-движок может не подхватиться (прогони setup-gost.sh)."

read_env() { # read_env KEY  — достаёт значение из .env (без экспорта секрета в лог)
    [ -f "$ENV_FILE" ] || return 1
    grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'
}

ENDPOINT="${EIS_ENDPOINT:-$(read_env EIS_ENDPOINT || true)}"
ENDPOINT="${ENDPOINT:-https://int44.zakupki.gov.ru/eis-integration/services/getDocsIP}"
TOKEN="${EIS_TOKEN:-$(read_env EIS_TOKEN || true)}"

HOST="$(printf '%s' "$ENDPOINT" | sed -E 's#https?://([^/:]+).*#\1#')"
PORT="$(printf '%s' "$ENDPOINT" | sed -E 's#https?://[^/:]+:([0-9]+).*#\1#; t; s#.*#443#')"

printf "Endpoint: %s\n" "$ENDPOINT"
printf "Host:Port: %s:%s\n" "$HOST" "$PORT"
if [ -n "$TOKEN" ]; then
    printf "Токен: задан (%s симв., %s…)\n" "${#TOKEN}" "$(printf '%s' "$TOKEN" | cut -c1-4)"
else
    err "EIS_TOKEN пуст — нет смысла бить запрос."; exit 1
fi

# --- Сборка SOAP-конверта (зеркалит SoapEisGateway) --------------------------
NS='http://zakupki.gov.ru/eis-integration/services/getDocsIP'
if [ -n "${REESTR:-}" ]; then
    SOAP_ACTION='getDocsByReestrNumber'
    BODY="<ns2:getDocsByReestrNumberRequest><ns2:reestrNumber>${REESTR}</ns2:reestrNumber></ns2:getDocsByReestrNumberRequest>"
    printf "Вызов: getDocsByReestrNumber(%s)\n" "$REESTR"
else
    SOAP_ACTION='getDocsByOrgRegion'
    REGION="${REGION:-77}"; SUBSYSTEM="${SUBSYSTEM:-PRIZ}"
    DATE="${DATE:-$(date -u -d 'yesterday' +%Y-%m-%d 2>/dev/null || date -u -v-1d +%Y-%m-%d)}"
    BODY="<ns2:getDocsByOrgRegionRequest><ns2:orgRegion>${REGION}</ns2:orgRegion><ns2:subsystemType>${SUBSYSTEM}</ns2:subsystemType><ns2:exactDate>${DATE}</ns2:exactDate></ns2:getDocsByOrgRegionRequest>"
    printf "Вызов: getDocsByOrgRegion(region=%s, subsystem=%s, date=%s)\n" "$REGION" "$SUBSYSTEM" "$DATE"
fi
ENVELOPE="<?xml version=\"1.0\" encoding=\"UTF-8\"?><S:Envelope xmlns:S=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:ns2=\"${NS}\"><S:Body>${BODY}</S:Body></S:Envelope>"

# ════════════════════════════════════════════════════════════════════════════
hdr "STAGE 0 — ГОСТ-handshake (openssl s_client)"
S_OUT="$(printf 'Q' | timeout 25 openssl s_client -connect "$HOST:$PORT" -servername "$HOST" -tls1_2 2>&1 || true)"
CIPHER="$(printf '%s' "$S_OUT" | grep -iE 'Cipher *:' | head -1 | sed -E 's/.*Cipher *: *//')"
if printf '%s' "$S_OUT" | grep -qiE 'GOST'; then
    ok "ГОСТ-handshake прошёл. Шифр: ${CIPHER:-?}"
elif printf '%s' "$S_OUT" | grep -qiE 'CONNECTED'; then
    warn "TCP+TLS соединились, но шифр НЕ ГОСТ (${CIPHER:-?}). Сервер мог принять обычный TLS — это ок для STAGE 1, но проверь, что движок реально активен."
else
    err "Соединение не установлено. Это транспорт: DNS / нет RU-IP / firewall / ГОСТ-движок не грузится."
    printf '%s\n' "$S_OUT" | tail -15 | sed 's/^/    /'
fi

# ════════════════════════════════════════════════════════════════════════════
hdr "STAGE 1 — реальный SOAP, curl -k (insecure: транспорт+токен+конверт)"
RESP_FILE="$(mktemp)"; HDR_FILE="$(mktemp)"
# .err/.err2 — сайдкары stderr curl (суффиксы к mktemp-имени), их тоже чистим.
trap 'rm -f "$RESP_FILE" "$HDR_FILE" "$HDR_FILE.err" "$HDR_FILE.err2"' EXIT
HTTP_CODE="$(curl -sk --max-time 60 -o "$RESP_FILE" -D "$HDR_FILE" -w '%{http_code}' \
    -X POST "$ENDPOINT" \
    -H "individualPerson_token: ${TOKEN}" \
    -H 'Content-Type: text/xml; charset=utf-8' \
    -H "SOAPAction: \"${SOAP_ACTION}\"" \
    --data-binary "$ENVELOPE" 2>"$HDR_FILE.err" || echo "000")"

printf "HTTP: %s\n" "$HTTP_CODE"
case "$HTTP_CODE" in
  000)
    err "curl не получил ответа. Транспорт (ГОСТ/сеть/RU-IP). Ошибка curl:"
    cat "$HDR_FILE.err" 2>/dev/null | sed 's/^/    /'
    ;;
  2*)
    if grep -qiE 'archiveUrl|noticeXml' "$RESP_FILE"; then
        ok "200 + данные есть (archiveUrl/noticeXml). ПАЙП РАБОТАЕТ. 🎉"
    elif grep -qiE 'Fault|faultstring|Error' "$RESP_FILE"; then
        warn "200, но в теле SOAP Fault — вероятно, форма конверта/имена элементов vs WSDL. Тело:"
        head -c 2500 "$RESP_FILE" | sed 's/^/    /'
    else
        warn "200, но ни данных, ни Fault. Возможно пустой период или иная структура ответа. Тело:"
        head -c 2500 "$RESP_FILE" | sed 's/^/    /'
    fi
    ;;
  401|403)
    err "HTTP $HTTP_CODE — токен отклонён / нет прав на метод. НЕ транспорт. Тело:"
    head -c 2500 "$RESP_FILE" | sed 's/^/    /'
    ;;
  *)
    err "HTTP $HTTP_CODE. Сервер ответил, но не 2xx. Тело:"
    head -c 2500 "$RESP_FILE" | sed 's/^/    /'
    ;;
esac

# ════════════════════════════════════════════════════════════════════════════
hdr "STAGE 2 — curl с проверкой CA (полный прод-путь)"
HTTP_CODE2="$(curl -s --max-time 60 -o /dev/null -w '%{http_code}' \
    -X POST "$ENDPOINT" \
    -H "individualPerson_token: ${TOKEN}" \
    -H 'Content-Type: text/xml; charset=utf-8' \
    -H "SOAPAction: \"${SOAP_ACTION}\"" \
    --data-binary "$ENVELOPE" 2>"$HDR_FILE.err2" || echo "000")"
if [ "$HTTP_CODE2" = "000" ] && grep -qiE 'certificate|verify|SSL' "$HDR_FILE.err2"; then
    warn "Проверка CA не прошла — нужен корневой сертификат Минцифры (ГОСТ) в системном trust store."
    warn "Для прода: установить «Корневой сертификат Минцифры» + промежуточный, обновить ca-certificates."
    sed 's/^/    /' "$HDR_FILE.err2" 2>/dev/null | head -8
elif [ "$HTTP_CODE2" = "$HTTP_CODE" ]; then
    ok "С проверкой CA результат тот же (HTTP $HTTP_CODE2) — безопасный путь работает."
else
    printf "HTTP с проверкой CA: %s (без проверки было %s)\n" "$HTTP_CODE2" "$HTTP_CODE"
fi

hdr "ИТОГ"
echo "Сопоставь результат с деревом диагнозов в scripts/eis-spike/README.md."
