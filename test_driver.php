<?php
echo "<h2>Available PDO Drivers:</h2>";
print_r(PDO::getAvailableDrivers());

echo "<h2>Loaded PHP Extensions:</h2>";
print_r(get_loaded_extensions());
?>