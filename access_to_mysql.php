<?php
// Set upload limits at runtime
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
ini_set('memory_limit', '2048M');
ini_set('max_execution_time', '0');
ini_set('max_input_time', '-1');

// Add error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
session_start();

// Improved file upload size check
function getMaxUploadSize() {
    $max_upload = (int)(ini_get('upload_max_filesize'));
    $max_post = (int)(ini_get('post_max_size'));
    $memory_limit = (int)(ini_get('memory_limit'));
    return min($max_upload, $max_post, $memory_limit);
}

// Add file size validation
function validateFileSize($file) {
    $maxSize = getMaxUploadSize() * 1024 * 1024; // Convert to bytes
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds limit of ' . getMaxUploadSize() . 'MB');
    }
    return true;
}

// Add function to get MySQL databases
function getMySQLDatabases($host, $user, $pass) {
    try {
        $conn = new PDO("mysql:host=$host", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $conn->query("SHOW DATABASES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch(PDOException $e) {
        throw new Exception("Could not connect to MySQL: " . $e->getMessage());
    }
}

function getTables($accessConn) {
    $tables = array();
    try {
        $schemaQuery = $accessConn->query("SELECT * FROM INFORMATION_SCHEMA_TABLES");
        if ($schemaQuery) {
            while ($row = $schemaQuery->fetch(PDO::FETCH_ASSOC)) {
                $fullTableName = $row['TABLE_SCHEMA'] . '_' . $row['TABLE_NAME'];
                $tables[] = $fullTableName;
            }
        }
    } catch (PDOException $e) {
        // Fallback to known table list
        $knownTables = array(
            'Ta_KIB_A', 'Ta_KIB_B', 'Ta_KIB_C', 
            'Ta_KIB_D', 'Ta_KIB_E', 'Ta_KIB_F'
        );
        
        foreach ($knownTables as $table) {
            try {
                $test = $accessConn->query("SELECT TOP 1 * FROM [$table]");
                if ($test) {
                    $tables[] = $table;
                }
            } catch (PDOException $tableError) {
                continue;
            }
        }
    }
    return $tables;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Import MS Access to MySQL</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input[type="file"] { margin-bottom: 10px; }
        .checkbox-list { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; }
        .checkbox-item { margin-bottom: 5px; }
        button { padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #45a049; }
        .progress { margin-top: 20px; }
        .error { color: red; padding: 10px; border: 1px solid red; margin: 10px 0; }
        .info { color: green; padding: 10px; border: 1px solid green; margin: 10px 0; }
        .select-buttons { margin: 10px 0; }
        .file-info { margin: 10px 0; padding: 10px; background: #f5f5f5; }
        .database-select { 
            width: 100%; 
            padding: 8px; 
            margin-bottom: 10px; 
        }
        .connection-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .connection-fields input {
            padding: 8px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Import MS Access Database to MySQL</h2>
        
        <?php if (!isset($_POST['step'])): ?>
        <!-- Step 1: File and Database Selection -->
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="step" value="1">
            
            <div class="form-group">
                <label>Select Access Database File:</label>
                <input type="file" name="access_file" accept=".mdb,.accdb" required>
                <p class="info">Maximum file size: <?php echo getMaxUploadSize(); ?>MB</p>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo getMaxUploadSize() * 1024 * 1024; ?>">
            </div>

            <div class="form-group">
                <label>MySQL Connection Details:</label>
                <div class="connection-fields">
                    <input type="text" name="mysql_host" value="localhost" placeholder="Host" required>
                    <input type="text" name="mysql_user" value="root" placeholder="Username" required>
                    <input type="password" name="mysql_pass" placeholder="Password">
                    <input type="text" name="mysql_db" placeholder="New Database Name" required>
                </div>
            </div>

            <div class="form-group">
                <label>Or Select Existing Database:</label>
                <select name="existing_db" class="database-select">
                    <option value="">Create New Database</option>
                    <?php 
                    try {
                        $databases = getMySQLDatabases('localhost', 'root', '');
                        foreach($databases as $db) {
                            if (!in_array($db, ['information_schema', 'mysql', 'performance_schema', 'phpmyadmin'])) {
                                echo "<option value='" . htmlspecialchars($db) . "'>" . htmlspecialchars($db) . "</option>";
                            }
                        }
                    } catch(Exception $e) {
                        echo "<option value='' disabled>Could not fetch databases</option>";
                    }
                    ?>
                </select>
            </div>

            <button type="submit">Next</button>
        </form>

        <script>
            document.querySelector('select[name="existing_db"]').addEventListener('change', function() {
                const newDbInput = document.querySelector('input[name="mysql_db"]');
                newDbInput.value = this.value;
                newDbInput.disabled = this.value !== '';
            });
        </script>

        <?php elseif ($_POST['step'] == '1'): ?>
        <!-- Step 2: Table Selection -->
        <?php
        try {
            if ($_FILES['access_file']['error'] === UPLOAD_ERR_INI_SIZE) {
                throw new Exception('The uploaded file exceeds the upload_max_filesize directive');
            }

            validateFileSize($_FILES['access_file']);

            $dbPath = $_FILES['access_file']['tmp_name'];
            $originalName = $_FILES['access_file']['name'];
            $accessDSN = "Driver={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $dbPath;
            $accessConn = new PDO("odbc:$accessDSN", "", "");
            $accessConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $tables = getTables($accessConn);
            $_SESSION['db_path'] = $dbPath;
            $_SESSION['original_name'] = $originalName;

            // Store MySQL connection details in session
            $_SESSION['mysql_host'] = $_POST['mysql_host'];
            $_SESSION['mysql_user'] = $_POST['mysql_user'];
            $_SESSION['mysql_pass'] = $_POST['mysql_pass'];
            $_SESSION['mysql_db'] = $_POST['existing_db'] ?: $_POST['mysql_db'];
        ?>
            <div class="file-info">
                <strong>Selected File:</strong> <?php echo htmlspecialchars($originalName); ?>
            </div>
            
            <form method="post" action="import.php">
                <input type="hidden" name="step" value="2">
                <div class="form-group">
                    <label>Select Tables to Import:</label>
                    <div class="select-buttons">
                        <button type="button" onclick="selectAll()">Select All</button>
                        <button type="button" onclick="deselectAll()">Deselect All</button>
                    </div>
                    <div class="checkbox-list">
                        <?php foreach ($tables as $table): ?>
                        <div class="checkbox-item">
                            <label>
                                <input type="checkbox" name="tables[]" value="<?php echo htmlspecialchars($table); ?>" class="table-checkbox">
                                <?php echo htmlspecialchars($table); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit">Start Import</button>
            </form>

            <script>
                function selectAll() {
                    document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = true);
                }
                function deselectAll() {
                    document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = false);
                }
            </script>
        <?php
        } catch (Exception $e) {
            echo "<div class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            echo "<p><a href='javascript:history.back()'>Go Back</a></p>";
        }
        endif; ?>
    </div>
</body>
</html>