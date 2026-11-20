#!/usr/bin/env bash
# Deploy / update SAPORÉ di Hostinger (butuh akses SSH).
#   bash ~/sapore-app/deploy/deploy.sh
# Variabel opsional: APP_DIR (bawaan ~/sapore-app), PHP_BIN (bawaan php), COMPOSER_BIN (bawaan composer), BRANCH (bawaan main)
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/sapore-app}"
PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_BIN:-composer}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"

echo "==> PHP yang dipakai: $($PHP -v | head -n1)"

echo "==> Backup database sebelum perubahan"
bash deploy/backup.sh pre-deploy

echo "==> Mode pemeliharaan ON"
$PHP artisan down --retry=60 || true
trap '$PHP artisan up' EXIT      # selalu dibuka lagi, bahkan bila langkah di bawah gagal

echo "==> Ambil kode ($BRANCH)"
git pull --ff-only origin "$BRANCH"

echo "==> Dependensi (tanpa paket pengembangan)"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Migrasi database"
$PHP artisan migrate --force

echo "==> Cache"
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
$PHP artisan icons:cache || true          # Blade Icons (Filament); lewati bila perintah tidak ada
$PHP artisan filament:optimize || true    # Filament >= 3.3; lewati bila tidak ada

echo "==> Pemeriksaan kesiapan"
$PHP artisan sapore:check || echo "!! Ada pemeriksaan GAGAL di atas. Perbaiki sebelum dipakai."

echo "==> Selesai"
