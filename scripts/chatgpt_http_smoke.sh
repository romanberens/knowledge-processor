#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8080}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

pass_count=0
fail_count=0

run_check() {
  local name="$1"
  local method="$2"
  local path="$3"
  local expected_code="$4"
  local expected_pattern="$5"
  local data="${6:-}"

  local body_file="$TMP_DIR/$(echo "$name" | tr ' /' '__').body"
  local url="${BASE_URL}${path}"
  local code

  if [[ "$method" == "POST" ]]; then
    if [[ -n "$data" ]]; then
      code=$(curl -sS -o "$body_file" -w "%{http_code}" -X POST "$url" --data "$data")
    else
      code=$(curl -sS -o "$body_file" -w "%{http_code}" -X POST "$url")
    fi
  else
    code=$(curl -sS -o "$body_file" -w "%{http_code}" "$url")
  fi

  if [[ "$code" != "$expected_code" ]]; then
    echo "[FAIL] $name -> HTTP $code (expected $expected_code)"
    fail_count=$((fail_count + 1))
    return
  fi

  if [[ -n "$expected_pattern" ]] && ! rg -q -- "$expected_pattern" "$body_file"; then
    echo "[FAIL] $name -> body mismatch (missing pattern: $expected_pattern)"
    fail_count=$((fail_count + 1))
    return
  fi

  echo "[PASS] $name -> HTTP $code"
  pass_count=$((pass_count + 1))
}

echo "Running ChatGPT HTTP smoke against: $BASE_URL"

run_check "session_page" "GET" "/?view=chatgpt&tab=session" "200" "chatgpt-shell"
run_check "status_page" "GET" "/?view=chatgpt&tab=status" "200" "chatgpt-session-panel"
run_check "auth_ajax" "GET" "/?view=chatgpt&tab=session&ajax=chatgpt_auth" "200" '"state"'
run_check "exchange_start_empty_prompt" "POST" "/?view=chatgpt&tab=session&ajax=chatgpt_exchange_start" "400" '"detail":"EMPTY_PROMPT"' "chatgpt_prompt=&chatgpt_assistant_id=chatgpt-5.2&chatgpt_project_id=lab-onenetworks"
run_check "sync_start_invalid_kind" "POST" "/?view=chatgpt&tab=session&ajax=chatgpt_sync_start" "400" '"detail":"SYNC_KIND_REQUIRED"' "sync_kind=invalid_kind"
run_check "sync_job_status_missing_id" "GET" "/?view=chatgpt&tab=session&ajax=chatgpt_sync_job_status" "400" '"detail":"JOB_ID_REQUIRED"'

echo "----"
echo "PASS: $pass_count"
echo "FAIL: $fail_count"

if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi
