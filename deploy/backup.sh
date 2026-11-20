#!/usr/bin/env bash
# Backup database SAPORÉ ke ~/sapore-backups (gzip). Dipakai oleh deploy.sh dan cron harian.
#   bash ~/sapore-app/deploy/backup.sh [label]      contoh label: daily, pre-deploy
# Membaca DB_* dari .env; berkas lama dihapus setelah KEEP_DAYS (bawaan 14 hari).
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/sapore-app}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/sapore-backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
LABEL="${1:-daily}"

envval() { grep -E "^$1=" "$APP_DIR/.env" | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

DB_HOST="$(envval DB_HOST)"; DB_PORT="$(envval DB_PORT)"; DB_NAME="$(envval DB_DATABASE)"
DB_USER="$(envval DB_USERNAME)"; DB_PASS="$(envval DB_PASSWORD)"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

FILE="$BACKUP_DIR/sapore-${LABEL}-$(date +%Y%m%d-%H%M%S).sql.gz"

# password lewat variabel lingkungan agar tidak tampil di daftar proses
MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --quick --no-tablespaces \
    -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" -u "$DB_USER" "$DB_NAME" | gzip > "$FILE"

# pastikan hasilnya bukan berkas kosong
if [ "$(stat -c%s "$FILE")" -lt 200 ]; then
    echo "!! Backup tampak kosong: $FILE" >&2
    rm -f "$FILE"
    exit 1
fi

find "$BACKUP_DIR" -name 'sapore-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

echo "Backup: $FILE ($(du -h "$FILE" | cut -f1))"
