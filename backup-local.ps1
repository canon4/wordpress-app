# Backup de la base de datos local (XAMPP)
# Ajusta la ruta de mysqldump si es distinta

$mysqldump = "C:\xampp\mysql\bin\mysqldump.exe"
$output    = ".\wooecomerce_backup.sql"

# Redirigir con Out-File -Encoding utf8 para evitar UTF-16 de PowerShell
& $mysqldump -u root -proot wooecomerce | Out-File -FilePath $output -Encoding utf8

Write-Host "Backup generado: $output"
