<?php
/**
 * Similar to phpMyAdmin - shows tables and records
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load configuration if exists
$configFile = __DIR__ . '/dbviewer_config.php';
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    // Default config
    $config = [
        'type' => 'mysqli',
        'host' => 'localhost',
        'port' => 7999,
        'username' => '',
        'password' => '',
        'database' => '',
        'socket' => '',
        'auto_detect' => true,
        'use_config_file' => true,  // Try to use /home/nxt/config
    ];
}

// Load the connection if auto-detect is enabled
if ($config['auto_detect'] ?? true) {
    // Set server variables needed for App.php - it might check the script name
    if (!isset($_SERVER['REQUEST_SCHEME'])) {
        $_SERVER['REQUEST_SCHEME'] = 'http';
    }
    if (!isset($_SERVER['HTTP_HOST'])) {
        $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
    // Set SCRIPT_NAME to match how the application expects it
    if (!isset($_SERVER['SCRIPT_NAME'])) {
        $_SERVER['SCRIPT_NAME'] = '/public/dbviewer.php';
    }
    if (!isset($_SERVER['PHP_SELF'])) {
        $_SERVER['PHP_SELF'] = '/public/dbviewer.php';
    }
    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = '/dbviewer.php';
    }
    
    require_once __DIR__ . '/../app/libs/connection.php';
    
    // Load App.php - it might create the connection automatically based on script name
    if (file_exists(__DIR__ . '/../app/libs/App.php')) {
        try {
            require_once __DIR__ . '/../app/libs/App.php';
        } catch (Exception $e) {
            // Continue without App.php
        }
    }
    
    require_once __DIR__ . '/../app/libs/helpers.php';
    
    // Try to read and decrypt config file if it exists
    if (($config['use_config_file'] ?? true) && file_exists('/home/nxt/config')) {
        $encryptedConfig = file_get_contents('/home/nxt/config');
        
        // Try to decrypt using decrypt_with_migration function (this is the correct one!)
        if (function_exists('decrypt_with_migration') && !empty($encryptedConfig)) {
            try {
                $decryptedConfig = decrypt_with_migration($encryptedConfig);
                
                if (!empty($decryptedConfig)) {
                    // The decrypted config is JSON format
                    $json = json_decode($decryptedConfig, true);
                    if ($json && is_array($json)) {
                        // Parse JSON format: {"host":"...","db_port":...,"db_user":"...","db_pass":"...","db_name":"..."}
                        $config['host'] = $json['host'] ?? $json['hostname'] ?? 'localhost';
                        $config['port'] = $json['db_port'] ?? $json['port'] ?? 7999;
                        $config['username'] = $json['db_user'] ?? $json['user'] ?? $json['username'] ?? '';
                        $config['password'] = $json['db_pass'] ?? $json['pass'] ?? $json['password'] ?? '';
                        $config['database'] = $json['db_name'] ?? $json['db'] ?? $json['database'] ?? $json['dbname'] ?? '';
                    } else {
                        // If not JSON, try colon-separated format
                        $separators = [':', '|', ',', ';', "\n", "\t"];
                        $parts = [];
                        
                        foreach ($separators as $sep) {
                            if (strpos($decryptedConfig, $sep) !== false) {
                                $parts = explode($sep, $decryptedConfig);
                                break;
                            }
                        }
                        
                        if (count($parts) >= 4) {
                            $config['username'] = trim($parts[0] ?? '');
                            $config['password'] = trim($parts[1] ?? '');
                            $config['database'] = trim($parts[2] ?? '');
                            $config['host'] = trim($parts[3] ?? 'localhost');
                            $config['port'] = isset($parts[4]) ? intval(trim($parts[4])) : 7999;
                        }
                    }
                }
            } catch (Exception $e) {
                // Decryption failed, will use manual config
                // Don't set error here, let it try manual config
            } catch (Error $e) {
                // Also catch fatal errors
            }
        }
        // Fallback to db_crypt if decrypt_with_migration doesn't exist
        elseif (function_exists('db_crypt') && !empty($encryptedConfig)) {
            try {
                $decryptedConfig = db_crypt($encryptedConfig);
                if (empty($decryptedConfig) && substr($encryptedConfig, 0, 3) === 'S1:') {
                    $decryptedConfig = db_crypt(substr($encryptedConfig, 3));
                }
                
                if (!empty($decryptedConfig)) {
                    $json = json_decode($decryptedConfig, true);
                    if ($json && is_array($json)) {
                        $config['host'] = $json['host'] ?? $json['hostname'] ?? 'localhost';
                        $config['port'] = $json['db_port'] ?? $json['port'] ?? 7999;
                        $config['username'] = $json['db_user'] ?? $json['user'] ?? $json['username'] ?? '';
                        $config['password'] = $json['db_pass'] ?? $json['pass'] ?? $json['password'] ?? '';
                        $config['database'] = $json['db_name'] ?? $json['db'] ?? $json['database'] ?? $json['dbname'] ?? '';
                    }
                }
            } catch (Exception $e) {
                // Decryption failed
            } catch (Error $e) {
                // Also catch fatal errors
            }
        }
    }
}

// App.php is already loaded above, so mark it as loaded
$appLoaded = file_exists(__DIR__ . '/../app/libs/App.php');

// Try to get database connection
$db = null;
$connectionError = null;

// First, check if App.php created a connection automatically
// Look for nGyAM instances that might have been created by App.php
foreach ($GLOBALS as $name => $value) {
    if (in_array($name, ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', 'argv', 'argc'])) {
        continue;
    }
    
    if (is_object($value)) {
        $class = get_class($value);
        // Check for nGyAM instances (the connection class)
        if ($class === 'nGyAM') {
            // Try to get the mysqli connection from nGyAM
            $ref = new ReflectionObject($value);
            $props = $ref->getProperties();
            foreach ($props as $p) {
                $p->setAccessible(true);
                $val = $p->getValue($value);
                if (is_object($val) && (get_class($val) === 'mysqli' || is_subclass_of($val, 'mysqli'))) {
                    $db = $val;
                    break 2;
                }
            }
            // If nGyAM has credentials, try to connect
            $hasCredentials = false;
            foreach ($props as $p) {
                $p->setAccessible(true);
                $val = $p->getValue($value);
                if (is_string($val) && strlen($val) > 0 && strlen($val) < 200) {
                    $hasCredentials = true;
                }
            }
            if ($hasCredentials) {
                try {
                    $result = $value->db_connect();
                    if ($result) {
                        $ref = new ReflectionObject($value);
                        $props = $ref->getProperties();
                        foreach ($props as $p) {
                            $p->setAccessible(true);
                            $val = $p->getValue($value);
                            if (is_object($val) && get_class($val) === 'mysqli') {
                                $db = $val;
                                break 2;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Continue searching
                }
            }
        }
        // Check for direct mysqli/PDO connections
        if ($class === 'mysqli' || is_subclass_of($value, 'mysqli')) {
            $db = $value;
            break;
        }
        if ($class === 'PDO' || is_subclass_of($value, 'PDO')) {
            $db = $value;
            break;
        }
    }
}

// Try to get connection from App class methods
if (!$db && $appLoaded) {
    $classes = get_declared_classes();
    foreach ($classes as $className) {
        if (stripos($className, 'App') !== false && $className !== 'AppendIterator') {
            // Try getInstance() if available
            if (method_exists($className, 'getInstance')) {
                try {
                    $inst = $className::getInstance();
                    $ref = new ReflectionObject($inst);
                    $props = $ref->getProperties();
                    foreach ($props as $p) {
                        $p->setAccessible(true);
                        $val = $p->getValue($inst);
                        if (is_object($val)) {
                            if (get_class($val) === 'nGyAM') {
                                // Extract mysqli from nGyAM
                                $refDb = new ReflectionObject($val);
                                $propsDb = $refDb->getProperties();
                                foreach ($propsDb as $pDb) {
                                    $pDb->setAccessible(true);
                                    $valDb = $pDb->getValue($val);
                                    if (is_object($valDb) && get_class($valDb) === 'mysqli') {
                                        $db = $valDb;
                                        break 2;
                                    }
                                }
                            } elseif (get_class($val) === 'mysqli' || get_class($val) === 'PDO') {
                                $db = $val;
                                break 2;
                            }
                        }
                    }
                } catch (Exception $e) {}
            }
            // Try getConnection() if available
            if (method_exists($className, 'getConnection')) {
                try {
                    $conn = $className::getConnection();
                    if (is_object($conn)) {
                        if (get_class($conn) === 'mysqli' || get_class($conn) === 'PDO') {
                            $db = $conn;
                            break;
                        } elseif (get_class($conn) === 'nGyAM') {
                            // Extract mysqli from nGyAM
                            $refDb = new ReflectionObject($conn);
                            $propsDb = $refDb->getProperties();
                            foreach ($propsDb as $pDb) {
                                $pDb->setAccessible(true);
                                $valDb = $pDb->getValue($conn);
                                if (is_object($valDb) && get_class($valDb) === 'mysqli') {
                                    $db = $valDb;
                                    break 2;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {}
            }
        }
    }
}

// Try to get connection from App class if it exists
if (!$db && $appLoaded) {
    $classes = get_declared_classes();
    foreach ($classes as $className) {
        if (stripos($className, 'App') !== false || stripos($className, 'Connection') !== false) {
            // Try static methods
            if (method_exists($className, 'getConnection')) {
                try {
                    $conn = $className::getConnection();
                    if (is_object($conn) && (get_class($conn) === 'mysqli' || get_class($conn) === 'PDO')) {
                        $db = $conn;
                        break;
                    }
                } catch (Exception $e) {}
            }
            if (method_exists($className, 'getInstance')) {
                try {
                    $inst = $className::getInstance();
                    if (is_object($inst) && method_exists($inst, 'getConnection')) {
                        $conn = $inst->getConnection();
                        if (is_object($conn) && (get_class($conn) === 'mysqli' || get_class($conn) === 'PDO')) {
                            $db = $conn;
                            break;
                        }
                    }
                } catch (Exception $e) {}
            }
        }
    }
}

// If no connection found, try to create one using defaults
if (!$db) {
    // Try to find connection through reflection or function calls
    $functions = get_defined_functions()['user'];
    foreach ($functions as $func) {
        if (stripos($func, 'connect') !== false || stripos($func, 'db') !== false) {
            try {
                // Skip functions that require parameters (like db_crypt)
                $ref = new ReflectionFunction($func);
                if ($ref->getNumberOfRequiredParameters() > 0) {
                    continue; // Skip functions that need parameters
                }
                $result = @call_user_func($func);
                if (is_object($result) && (get_class($result) === 'mysqli' || get_class($result) === 'PDO')) {
                    $db = $result;
                    break;
                }
            } catch (Exception $e) {
                // Ignore
            } catch (Error $e) {
                // Ignore fatal errors too
            }
        }
    }
    
    // Try to create connection using decrypted config
    if (!$db && !empty($config['host']) && !empty($config['username'])) {
        try {
            if ($config['type'] === 'pdo') {
                $dsn = "mysql:host={$config['host']};port={$config['port']}";
                if (!empty($config['database'])) {
                    $dsn .= ";dbname=" . $config['database'];
                }
                $db = new PDO($dsn, $config['username'], $config['password']);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                // MySQLi
                $db = @new mysqli($config['host'], $config['username'], $config['password'], $config['database'] ?? '', $config['port']);
                
                if ($db->connect_error) {
                    $connectionError = "MySQLi connection failed: " . $db->connect_error;
                    $db = null;
                }
            }
        } catch (Exception $e) {
            $connectionError = "Connection error: " . $e->getMessage();
        }
    }
    
    // Last resort: try manual configuration
    if (!$db && !($config['auto_detect'] ?? true)) {
        try {
            $host = $config['host'] ?? 'localhost';
            $port = intval($config['port'] ?? 3306);
            $user = $config['username'] ?? '';
            $pass = $config['password'] ?? '';
            $socket = $config['socket'] ?? '';
            
            if ($config['type'] === 'pdo') {
                if ($socket) {
                    $dsn = "mysql:unix_socket=$socket";
                } else {
                    $dsn = "mysql:host=$host;port=$port";
                }
                if (!empty($config['database'])) {
                    $dsn .= ";dbname=" . $config['database'];
                }
                $db = new PDO($dsn, $user, $pass);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } else {
                // MySQLi
                if ($socket) {
                    $db = @new mysqli(null, $user, $pass, $config['database'] ?? '', 0, $socket);
                } else {
                    $db = @new mysqli($host, $user, $pass, $config['database'] ?? '', $port);
                }
                
                if ($db->connect_error) {
                    $connectionError = "MySQLi connection failed: " . $db->connect_error;
                    $db = null;
                }
            }
        } catch (Exception $e) {
            $connectionError = "Connection error: " . $e->getMessage();
        }
    } elseif (!$db) {
        $connectionError = "Could not automatically detect database connection. ";
        $connectionError .= "Please edit /home/nxt/public/dbviewer_config.php to configure the connection manually.";
    }
}

// Handle actions (edit, insert, delete, export)
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$actionMessage = '';
$actionError = '';

if ($db && $action) {
    $currentTable = $_GET['table'] ?? $_POST['table'] ?? '';
    
    if ($action === 'delete' && !empty($_GET['id']) && !empty($currentTable)) {
        // Delete record
        try {
            // Get primary key column
            $primaryKey = null;
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW KEYS FROM `" . $db->real_escape_string($currentTable) . "` WHERE Key_name = 'PRIMARY'");
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $primaryKey = $row['Column_name'];
                    $result->free();
                }
            } elseif ($db instanceof PDO) {
                $stmt = $db->query("SHOW KEYS FROM `" . str_replace('`', '``', $currentTable) . "` WHERE Key_name = 'PRIMARY'");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $primaryKey = $row['Column_name'];
                    }
                }
            }
            
            if ($primaryKey) {
                $id = $_GET['id'];
                if ($db instanceof mysqli) {
                    $stmt = $db->prepare("DELETE FROM `" . $db->real_escape_string($currentTable) . "` WHERE `" . $db->real_escape_string($primaryKey) . "` = ?");
                    $stmt->bind_param("s", $id);
                    if ($stmt->execute()) {
                        header("Location: ?table=" . urlencode($currentTable) . "&page=" . ($_GET['page'] ?? 1) . "&msg=deleted");
                        exit;
                    } else {
                        $actionError = "Error deleting record: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $stmt = $db->prepare("DELETE FROM `" . str_replace('`', '``', $currentTable) . "` WHERE `" . str_replace('`', '``', $primaryKey) . "` = ?");
                    if ($stmt->execute([$id])) {
                        header("Location: ?table=" . urlencode($currentTable) . "&page=" . ($_GET['page'] ?? 1) . "&msg=deleted");
                        exit;
                    } else {
                        $actionError = "Error deleting record";
                    }
                }
            } else {
                $actionError = "Could not find primary key for table";
            }
        } catch (Exception $e) {
            $actionError = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'update' && !empty($_POST['id']) && !empty($currentTable)) {
        // Update record
        try {
            $primaryKey = null;
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW KEYS FROM `" . $db->real_escape_string($currentTable) . "` WHERE Key_name = 'PRIMARY'");
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $primaryKey = $row['Column_name'];
                    $result->free();
                }
            } elseif ($db instanceof PDO) {
                $stmt = $db->query("SHOW KEYS FROM `" . str_replace('`', '``', $currentTable) . "` WHERE Key_name = 'PRIMARY'");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $primaryKey = $row['Column_name'];
                    }
                }
            }
            
            if ($primaryKey) {
                // Get columns
                $columns = [];
                if ($db instanceof mysqli) {
                    $result = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($currentTable) . "`");
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $columns[] = $row['Field'];
                        }
                        $result->free();
                    }
                } else {
                    $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $currentTable) . "`");
                    if ($stmt) {
                        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                }
                
                // Build UPDATE query
                $setParts = [];
                $values = [];
                foreach ($columns as $col) {
                    if ($col !== $primaryKey && isset($_POST['field_' . $col])) {
                        $setParts[] = "`" . ($db instanceof mysqli ? $db->real_escape_string($col) : str_replace('`', '``', $col)) . "` = ?";
                        $values[] = $_POST['field_' . $col];
                    }
                }
                
                if (!empty($setParts)) {
                    $values[] = $_POST['id'];
                    $sql = "UPDATE `" . ($db instanceof mysqli ? $db->real_escape_string($currentTable) : str_replace('`', '``', $currentTable)) . "` SET " . implode(", ", $setParts) . " WHERE `" . ($db instanceof mysqli ? $db->real_escape_string($primaryKey) : str_replace('`', '``', $primaryKey)) . "` = ?";
                    
                    if ($db instanceof mysqli) {
                        $stmt = $db->prepare($sql);
                        $types = str_repeat('s', count($values));
                        $stmt->bind_param($types, ...$values);
                        if ($stmt->execute()) {
                            header("Location: ?table=" . urlencode($currentTable) . "&page=" . ($_POST['page'] ?? 1) . "&msg=updated");
                            exit;
                        } else {
                            $actionError = "Error updating record: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute($values)) {
                            header("Location: ?table=" . urlencode($currentTable) . "&page=" . ($_POST['page'] ?? 1) . "&msg=updated");
                            exit;
                        } else {
                            $actionError = "Error updating record";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $actionError = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'insert' && !empty($_POST) && !empty($currentTable)) {
        // Insert new record
        try {
            // Get columns
            $columns = [];
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($currentTable) . "`");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $columns[] = $row;
                    }
                    $result->free();
                }
            } else {
                $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $currentTable) . "`");
                if ($stmt) {
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            
            // Build INSERT query
            $insertCols = [];
            $insertVals = [];
            $values = [];
            
            foreach ($columns as $col) {
                $fieldName = is_array($col) ? $col['Field'] : $col;
                $isAutoIncrement = (is_array($col) && stripos($col['Extra'] ?? '', 'auto_increment') !== false);
                
                if (!$isAutoIncrement && isset($_POST['field_' . $fieldName])) {
                    $insertCols[] = "`" . ($db instanceof mysqli ? $db->real_escape_string($fieldName) : str_replace('`', '``', $fieldName)) . "`";
                    $insertVals[] = "?";
                    $values[] = $_POST['field_' . $fieldName] === '' ? null : $_POST['field_' . $fieldName];
                }
            }
            
            if (!empty($insertCols)) {
                $sql = "INSERT INTO `" . ($db instanceof mysqli ? $db->real_escape_string($currentTable) : str_replace('`', '``', $currentTable)) . "` (" . implode(", ", $insertCols) . ") VALUES (" . implode(", ", $insertVals) . ")";
                
                if ($db instanceof mysqli) {
                    $stmt = $db->prepare($sql);
                    $types = str_repeat('s', count($values));
                    $stmt->bind_param($types, ...$values);
                    if ($stmt->execute()) {
                        header("Location: ?table=" . urlencode($currentTable) . "&page=1&msg=inserted");
                        exit;
                    } else {
                        $actionError = "Error inserting record: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute($values)) {
                        header("Location: ?table=" . urlencode($currentTable) . "&page=1&msg=inserted");
                        exit;
                    } else {
                        $actionError = "Error inserting record";
                    }
                }
            }
        } catch (Exception $e) {
            $actionError = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'export' && !empty($currentTable)) {
        // Export SQL dump
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $currentTable . '_' . date('Y-m-d_H-i-s') . '.sql"');
        
        try {
            // Get table structure
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW CREATE TABLE `" . $db->real_escape_string($currentTable) . "`");
                if ($result) {
                    $row = $result->fetch_array();
                    echo "-- Table structure for `" . $currentTable . "`\n";
                    echo $row[1] . ";\n\n";
                    $result->free();
                }
                
                // Get table data
                $result = $db->query("SELECT * FROM `" . $db->real_escape_string($currentTable) . "`");
                if ($result) {
                    echo "-- Data for table `" . $currentTable . "`\n";
                    while ($row = $result->fetch_assoc()) {
                        $values = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = "'" . $db->real_escape_string($val) . "'";
                            }
                        }
                        echo "INSERT INTO `" . $currentTable . "` VALUES (" . implode(", ", $values) . ");\n";
                    }
                    $result->free();
                }
            } else {
                $stmt = $db->query("SHOW CREATE TABLE `" . str_replace('`', '``', $currentTable) . "`");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_NUM);
                    echo "-- Table structure for `" . $currentTable . "`\n";
                    echo $row[1] . ";\n\n";
                }
                
                $stmt = $db->query("SELECT * FROM `" . str_replace('`', '``', $currentTable) . "`");
                if ($stmt) {
                    echo "-- Data for table `" . $currentTable . "`\n";
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $values = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = "'" . str_replace("'", "''", $val) . "'";
                            }
                        }
                        echo "INSERT INTO `" . $currentTable . "` VALUES (" . implode(", ", $values) . ");\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo "-- Error: " . $e->getMessage();
        }
        exit;
    } elseif ($action === 'get_columns' && !empty($currentTable) && $db) {
        // AJAX endpoint to get column info
        header('Content-Type: application/json');
        try {
            $columns = [];
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($currentTable) . "`");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $columns[] = $row;
                    }
                    $result->free();
                }
            } elseif ($db instanceof PDO) {
                $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $currentTable) . "`");
                if ($stmt) {
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            echo json_encode(['success' => true, 'columns' => $columns]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($action === 'get_record' && !empty($_GET['id']) && !empty($currentTable) && $db) {
        // AJAX endpoint to get record data for editing
        header('Content-Type: application/json');
        try {
            $primaryKey = null;
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW KEYS FROM `" . $db->real_escape_string($currentTable) . "` WHERE Key_name = 'PRIMARY'");
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $primaryKey = $row['Column_name'];
                    $result->free();
                }
            } elseif ($db instanceof PDO) {
                $stmt = $db->query("SHOW KEYS FROM `" . str_replace('`', '``', $currentTable) . "` WHERE Key_name = 'PRIMARY'");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $primaryKey = $row['Column_name'];
                    }
                }
            }
            
            if ($primaryKey) {
                $id = $_GET['id'];
                if ($db instanceof mysqli) {
                    $stmt = $db->prepare("SELECT * FROM `" . $db->real_escape_string($currentTable) . "` WHERE `" . $db->real_escape_string($primaryKey) . "` = ?");
                    $stmt->bind_param("s", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        $record = $result->fetch_assoc();
                        echo json_encode(['success' => true, 'record' => $record, 'primaryKey' => $primaryKey]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Record not found']);
                    }
                    $stmt->close();
                } else {
                    $stmt = $db->prepare("SELECT * FROM `" . str_replace('`', '``', $currentTable) . "` WHERE `" . str_replace('`', '``', $primaryKey) . "` = ?");
                    $stmt->execute([$id]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($record) {
                        echo json_encode(['success' => true, 'record' => $record, 'primaryKey' => $primaryKey]);
                    } else {
                        echo json_encode(['success' => false, 'error' => 'Record not found']);
                    }
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Primary key not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    } elseif ($action === 'export_db' && $db) {
        // Export entire database
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="database_' . date('Y-m-d_H-i-s') . '.sql"');
        
        try {
            // Get database name
            $dbName = '';
            if ($db instanceof mysqli) {
                $result = $db->query("SELECT DATABASE()");
                if ($result) {
                    $row = $result->fetch_array();
                    $dbName = $row[0] ?? '';
                    $result->free();
                }
            } else {
                $stmt = $db->query("SELECT DATABASE()");
                if ($stmt) {
                    $dbName = $stmt->fetchColumn();
                }
            }
            
            echo "-- Database dump: " . $dbName . "\n";
            echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
            
            // Get all tables
            $tables = [];
            if ($db instanceof mysqli) {
                $result = $db->query("SHOW TABLES");
                if ($result) {
                    while ($row = $result->fetch_array()) {
                        $tables[] = $row[0];
                    }
                    $result->free();
                }
            } else {
                $stmt = $db->query("SHOW TABLES");
                if ($stmt) {
                    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            }
            
            foreach ($tables as $table) {
                // Table structure
                if ($db instanceof mysqli) {
                    $result = $db->query("SHOW CREATE TABLE `" . $db->real_escape_string($table) . "`");
                    if ($result) {
                        $row = $result->fetch_array();
                        echo "\n-- Table structure for `" . $table . "`\n";
                        echo $row[1] . ";\n\n";
                        $result->free();
                    }
                    
                    // Table data
                    $result = $db->query("SELECT * FROM `" . $db->real_escape_string($table) . "`");
                    if ($result) {
                        echo "-- Data for table `" . $table . "`\n";
                        while ($row = $result->fetch_assoc()) {
                            $values = [];
                            foreach ($row as $val) {
                                if ($val === null) {
                                    $values[] = 'NULL';
                                } else {
                                    $values[] = "'" . $db->real_escape_string($val) . "'";
                                }
                            }
                            echo "INSERT INTO `" . $table . "` VALUES (" . implode(", ", $values) . ");\n";
                        }
                        $result->free();
                    }
                } else {
                    $stmt = $db->query("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`");
                    if ($stmt) {
                        $row = $stmt->fetch(PDO::FETCH_NUM);
                        echo "\n-- Table structure for `" . $table . "`\n";
                        echo $row[1] . ";\n\n";
                    }
                    
                    $stmt = $db->query("SELECT * FROM `" . str_replace('`', '``', $table) . "`");
                    if ($stmt) {
                        echo "-- Data for table `" . $table . "`\n";
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $values = [];
                            foreach ($row as $val) {
                                if ($val === null) {
                                    $values[] = 'NULL';
                                } else {
                                    $values[] = "'" . str_replace("'", "''", $val) . "'";
                                }
                            }
                            echo "INSERT INTO `" . $table . "` VALUES (" . implode(", ", $values) . ");\n";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo "-- Error: " . $e->getMessage();
        }
        exit;
    }
}

// Get current table and page
$currentTable = $_GET['table'] ?? '';
$currentPage = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($currentPage - 1) * $perPage;

// Get tables list
$tables = [];
$databases = [];
if ($db) {
    // First, get list of databases if no specific database is set
    if (empty($config['database'])) {
        if ($db instanceof mysqli) {
            $result = $db->query("SHOW DATABASES");
            if ($result) {
                while ($row = $result->fetch_array()) {
                    $dbName = $row[0];
                    // Filter out system databases
                    if (!in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $databases[] = $dbName;
                    }
                }
                $result->free();
            }
            // Use first database or show database selector
            if (!empty($databases) && empty($_GET['db'])) {
                $selectedDb = $databases[0];
                $db->select_db($selectedDb);
            } elseif (!empty($_GET['db'])) {
                $selectedDb = $_GET['db'];
                $db->select_db($selectedDb);
            }
        } elseif ($db instanceof PDO) {
            $stmt = $db->query("SHOW DATABASES");
            if ($stmt) {
                $allDbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allDbs as $dbName) {
                    if (!in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                        $databases[] = $dbName;
                    }
                }
            }
            if (!empty($databases) && empty($_GET['db'])) {
                $selectedDb = $databases[0];
                $db->exec("USE `" . str_replace('`', '``', $selectedDb) . "`");
            } elseif (!empty($_GET['db'])) {
                $selectedDb = $_GET['db'];
                $db->exec("USE `" . str_replace('`', '``', $selectedDb) . "`");
            }
        }
    }
    
    // Get tables
    if ($db instanceof mysqli) {
        $result = $db->query("SHOW TABLES");
        if ($result) {
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            $result->free();
        }
    } elseif ($db instanceof PDO) {
        $stmt = $db->query("SHOW TABLES");
        if ($stmt) {
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// Get table data if table is selected
$tableData = [];
$totalRecords = 0;
$columns = [];
$currentDatabase = '';

if ($db && $currentTable) {
    // Get current database
    if ($db instanceof mysqli) {
        $result = $db->query("SELECT DATABASE()");
        if ($result) {
            $row = $result->fetch_array();
            $currentDatabase = $row[0] ?? '';
            $result->free();
        }
        
        // Get columns
        $result = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($currentTable) . "`");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row;
            }
            $result->free();
        }
        
        // Get total count
        $result = $db->query("SELECT COUNT(*) as total FROM `" . $db->real_escape_string($currentTable) . "`");
        if ($result) {
            $row = $result->fetch_assoc();
            $totalRecords = intval($row['total']);
            $result->free();
        }
        
        // Get data
        $query = "SELECT * FROM `" . $db->real_escape_string($currentTable) . "` LIMIT $perPage OFFSET $offset";
        $result = $db->query($query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tableData[] = $row;
            }
            $result->free();
        }
    } elseif ($db instanceof PDO) {
        // Get current database
        $stmt = $db->query("SELECT DATABASE()");
        if ($stmt) {
            $currentDatabase = $stmt->fetchColumn();
        }
        
        // Get columns
        $stmt = $db->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $currentTable) . "`");
        if ($stmt) {
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Get total count
        $stmt = $db->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $currentTable) . "`");
        if ($stmt) {
            $totalRecords = intval($stmt->fetchColumn());
        }
        
        // Get data
        $query = "SELECT * FROM `" . str_replace('`', '``', $currentTable) . "` LIMIT $perPage OFFSET $offset";
        $stmt = $db->query($query);
        if ($stmt) {
            $tableData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$totalPages = $totalRecords > 0 ? ceil($totalRecords / $perPage) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NXT Panel</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header .db-info {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .container {
            display: flex;
            height: calc(100vh - 100px);
        }
        
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
        }
        
        .sidebar-header {
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #495057;
        }
        
        .table-list {
            list-style: none;
        }
        
        .table-item {
            border-bottom: 1px solid #f0f0f0;
        }
        
        .table-item a {
            display: block;
            padding: 12px 15px;
            color: #495057;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .table-item a:hover {
            background: #f8f9fa;
            color: #667eea;
        }
        
        .table-item.active a {
            background: #667eea;
            color: white;
            font-weight: 500;
        }
        
        .main-content {
            flex: 1;
            overflow-y: auto;
            background: white;
            padding: 20px;
        }
        
        .content-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .content-header h2 {
            color: #495057;
            margin-bottom: 5px;
        }
        
        .table-info {
            color: #6c757d;
            font-size: 14px;
        }
        
        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 20px;
            border: 1px solid #f5c6cb;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-radius: 5px;
            overflow: hidden;
        }
        
        .data-table thead {
            background: #f8f9fa;
        }
        
        .data-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        
        .data-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .pagination {
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .pagination-info {
            color: #6c757d;
            font-size: 14px;
        }
        
        .pagination-controls {
            display: flex;
            gap: 5px;
        }
        
        .pagination-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            color: #495057;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background: #5568d3;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-primary {
            background: #667eea;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .btn-edit {
            background: #28a745;
        }
        
        .btn-delete {
            background: #dc3545;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin: 20px 0;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #495057;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #6c757d;
            font-size: 12px;
        }
        
        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        
        .success-box {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin: 15px 0;
            border: 1px solid #c3e6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .cell-content {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .cell-content.large-value {
            cursor: pointer;
            color: #667eea;
            text-decoration: underline;
        }
        
        .cell-content.large-value:hover {
            color: #5568d3;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 80%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #495057;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 20px;
        }
        
        .close:hover,
        .close:focus {
            color: #000;
            text-decoration: none;
        }
        
        .modal-body {
            word-break: break-all;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .no-tables {
            padding: 40px 20px;
            text-align: center;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>NXT Panel</h1>
        <div class="db-info">
            <?php if ($db): ?>
                <?php if ($db instanceof mysqli): ?>
                    Connected to: <?php echo htmlspecialchars($db->host_info); ?>
                <?php elseif ($db instanceof PDO): ?>
                    Connected via PDO
                <?php endif; ?>
                <?php if ($currentDatabase): ?>
                    | Database: <strong><?php echo htmlspecialchars($currentDatabase); ?></strong>
                <?php endif; ?>
            <?php else: ?>
                <span style="color: #ffc107;">⚠️ Not Connected</span>
            <?php endif; ?>
        </div>
    </div>
    
            <?php if ($connectionError): ?>
        <div class="error-box">
            <strong>Connection Error:</strong> <?php echo htmlspecialchars($connectionError); ?>
            <br><br>
            <strong>Note:</strong> The database credentials are encrypted in <code>/home/nxt/config</code> file.
            <br><br>
            <strong>To fix this, you have two options:</strong>
            <ol style="margin-left: 20px; margin-top: 10px;">
                <li><strong>Option 1 - Manual Configuration:</strong>
                    <ul>
                        <li>Edit <code>/home/nxt/public/dbviewer_config.php</code></li>
                        <li>Set <code>auto_detect</code> to <code>false</code></li>
                        <li>Enter your database connection details (host, port, username, password)</li>
                        <li>Save and refresh this page</li>
                    </ul>
                </li>
                <li><strong>Option 2 - Decrypt Config File:</strong>
                    <ul>
                        <li>The config file at <code>/home/nxt/config</code> contains encrypted credentials</li>
                        <li>You need to decrypt it using the NXT Panel's decryption functions</li>
                        <li>The format appears to be: <code>S1:&lt;encrypted_data&gt;</code></li>
                        <li>Once decrypted, you can extract the credentials and use Option 1</li>
                    </ul>
                </li>
            </ol>
            <br>
            <strong>Config file info:</strong>
            <?php if (file_exists('/home/nxt/config')): ?>
                <br>File exists: Yes
                <br>Size: <?php echo filesize('/home/nxt/config'); ?> bytes
                <br>Format: <?php echo substr(file_get_contents('/home/nxt/config'), 0, 10); ?>...
            <?php else: ?>
                <br>File does not exist
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="container">
        <div class="sidebar">
            <?php if (!empty($databases) && count($databases) > 1): ?>
                <div class="sidebar-header" style="background: #e3f2fd;">
                    🗄️ Database
                    <select onchange="window.location='?db='+this.value" style="width:100%; margin-top:5px; padding:5px;">
                        <?php foreach ($databases as $dbName): ?>
                            <option value="<?php echo htmlspecialchars($dbName); ?>" <?php echo (($_GET['db'] ?? $databases[0]) === $dbName) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dbName); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="sidebar-header">
                📋 Tables (<?php echo count($tables); ?>)
            </div>
            <?php if (empty($tables)): ?>
                <div class="no-tables">No tables found</div>
            <?php else: ?>
                <ul class="table-list">
                    <?php foreach ($tables as $table): ?>
                        <li class="table-item <?php echo $table === $currentTable ? 'active' : ''; ?>">
                            <a href="?table=<?php echo urlencode($table); ?>">
                                📊 <?php echo htmlspecialchars($table); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <div class="main-content">
            <?php if (!$currentTable): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👈</div>
                    <h3>Select a table from the left menu</h3>
                    <p>Choose a table to view its records</p>
                </div>
            <?php elseif ($action === 'edit' && !empty($_GET['id']) && $db): ?>
                <?php
                // Get primary key
                $primaryKey = null;
                if ($db instanceof mysqli) {
                    $result = $db->query("SHOW KEYS FROM `" . $db->real_escape_string($currentTable) . "` WHERE Key_name = 'PRIMARY'");
                    if ($result && $result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $primaryKey = $row['Column_name'];
                        $result->free();
                    }
                } elseif ($db instanceof PDO) {
                    $stmt = $db->query("SHOW KEYS FROM `" . str_replace('`', '``', $currentTable) . "` WHERE Key_name = 'PRIMARY'");
                    if ($stmt) {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $primaryKey = $row['Column_name'];
                        }
                    }
                }
                
                // Get current record
                $currentRecord = null;
                if ($primaryKey) {
                    $id = $_GET['id'];
                    if ($db instanceof mysqli) {
                        $stmt = $db->prepare("SELECT * FROM `" . $db->real_escape_string($currentTable) . "` WHERE `" . $db->real_escape_string($primaryKey) . "` = ?");
                        $stmt->bind_param("s", $id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result) {
                            $currentRecord = $result->fetch_assoc();
                        }
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare("SELECT * FROM `" . str_replace('`', '``', $currentTable) . "` WHERE `" . str_replace('`', '``', $primaryKey) . "` = ?");
                        $stmt->execute([$id]);
                        $currentRecord = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                }
                ?>
                <div class="content-header">
                    <h2>✏️ Edit Record - <?php echo htmlspecialchars($currentTable); ?></h2>
                    <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage; ?>" class="btn btn-secondary">← Back to Table</a>
                </div>
                
                <?php if ($currentRecord): ?>
                    <div class="form-container">
                        <form method="POST" action="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage; ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($currentRecord[$primaryKey] ?? ''); ?>">
                            
                            <?php foreach ($columns as $col): ?>
                                <?php if ($col['Field'] === $primaryKey): ?>
                                    <div class="form-group">
                                        <label><?php echo htmlspecialchars($col['Field']); ?> (Primary Key)</label>
                                        <input type="text" value="<?php echo htmlspecialchars($currentRecord[$col['Field']] ?? ''); ?>" disabled>
                                        <small>Primary key cannot be edited</small>
                                    </div>
                                <?php else: ?>
                                    <div class="form-group">
                                        <label><?php echo htmlspecialchars($col['Field']); ?></label>
                                        <?php
                                        $fieldType = strtolower($col['Type'] ?? '');
                                        $isTextarea = (stripos($fieldType, 'text') !== false || stripos($fieldType, 'blob') !== false);
                                        $value = $currentRecord[$col['Field']] ?? '';
                                        ?>
                                        <?php if ($isTextarea): ?>
                                            <textarea name="field_<?php echo htmlspecialchars($col['Field']); ?>" rows="4"><?php echo htmlspecialchars($value); ?></textarea>
                                        <?php else: ?>
                                            <input type="text" name="field_<?php echo htmlspecialchars($col['Field']); ?>" value="<?php echo htmlspecialchars($value); ?>">
                                        <?php endif; ?>
                                        <small>Type: <?php echo htmlspecialchars($col['Type'] ?? 'unknown'); ?></small>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage; ?>" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="error-box">Record not found</div>
                <?php endif; ?>
            <?php elseif ($action === 'insert' && $db): ?>
                <div class="content-header">
                    <h2>➕ Insert New Record - <?php echo htmlspecialchars($currentTable); ?></h2>
                    <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage; ?>" class="btn btn-secondary">← Back to Table</a>
                </div>
                
                <div class="form-container">
                    <form method="POST" action="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage; ?>">
                        <input type="hidden" name="action" value="insert">
                        
                        <?php foreach ($columns as $col): ?>
                            <?php
                            $isAutoIncrement = stripos($col['Extra'] ?? '', 'auto_increment') !== false;
                            if ($isAutoIncrement) continue;
                            
                            $fieldType = strtolower($col['Type'] ?? '');
                            $isTextarea = (stripos($fieldType, 'text') !== false || stripos($fieldType, 'blob') !== false);
                            $isNull = ($col['Null'] ?? '') === 'YES';
                            $defaultValue = $col['Default'] ?? null;
                            ?>
                            <div class="form-group">
                                <label><?php echo htmlspecialchars($col['Field']); ?> <?php if (!$isNull): ?><span style="color: red;">*</span><?php endif; ?></label>
                                <?php if ($isTextarea): ?>
                                    <textarea name="field_<?php echo htmlspecialchars($col['Field']); ?>" rows="4" <?php if (!$isNull && $defaultValue === null): ?>required<?php endif; ?>><?php echo htmlspecialchars($defaultValue ?? ''); ?></textarea>
                                <?php else: ?>
                                    <input type="text" name="field_<?php echo htmlspecialchars($col['Field']); ?>" value="<?php echo htmlspecialchars($defaultValue ?? ''); ?>" <?php if (!$isNull && $defaultValue === null): ?>required<?php endif; ?>>
                                <?php endif; ?>
                                <small>Type: <?php echo htmlspecialchars($col['Type'] ?? 'unknown'); ?> <?php if (!$isNull): ?>(Required)<?php endif; ?></small>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">➕ Insert Record</button>
                            <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage; ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php elseif (empty($tableData) && $totalRecords === 0): ?>
                <div class="content-header">
                    <h2>📊 <?php echo htmlspecialchars($currentTable); ?></h2>
                    <div class="table-info">Table is empty</div>
                    <div class="action-buttons" style="margin-top: 15px;">
                        <a href="?table=<?php echo urlencode($currentTable); ?>&action=insert" class="btn btn-primary">➕ Insert New Record</a>
                        <a href="?table=<?php echo urlencode($currentTable); ?>&action=export" class="btn btn-secondary">💾 Export Table SQL</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="content-header">
                    <h2>📊 <?php echo htmlspecialchars($currentTable); ?></h2>
                    <div class="table-info">
                        Showing <?php echo number_format($offset + 1); ?> - <?php echo number_format(min($offset + $perPage, $totalRecords)); ?> 
                        of <?php echo number_format($totalRecords); ?> records
                    </div>
                    <div class="action-buttons" style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="?table=<?php echo urlencode($currentTable); ?>&action=insert" class="btn btn-primary">➕ Insert New Record</a>
                        <a href="?table=<?php echo urlencode($currentTable); ?>&action=export" class="btn btn-secondary">💾 Export Table SQL</a>
                        <a href="?action=export_db" class="btn btn-secondary" onclick="return confirm('Export entire database? This may take a while.')">💾 Export Database SQL</a>
                    </div>
                </div>
                
                <?php if (isset($_GET['msg'])): ?>
                    <?php
                    $msg = $_GET['msg'];
                    $messages = [
                        'deleted' => 'Record deleted successfully',
                        'updated' => 'Record updated successfully',
                        'inserted' => 'Record inserted successfully'
                    ];
                    if (isset($messages[$msg])):
                    ?>
                        <div class="success-box" style="background: #d4edda; color: #155724; padding: 12px; border-radius: 5px; margin: 15px 0; border: 1px solid #c3e6cb;">
                            ✅ <?php echo htmlspecialchars($messages[$msg]); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($actionError): ?>
                    <div class="error-box" style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 5px; margin: 15px 0; border: 1px solid #f5c6cb;">
                        ❌ <?php echo htmlspecialchars($actionError); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($columns)): ?>
                    <?php
                    // Get primary key
                    $primaryKey = null;
                    if ($db instanceof mysqli) {
                        $result = $db->query("SHOW KEYS FROM `" . $db->real_escape_string($currentTable) . "` WHERE Key_name = 'PRIMARY'");
                        if ($result && $result->num_rows > 0) {
                            $row = $result->fetch_assoc();
                            $primaryKey = $row['Column_name'];
                            $result->free();
                        }
                    } elseif ($db instanceof PDO) {
                        $stmt = $db->query("SHOW KEYS FROM `" . str_replace('`', '``', $currentTable) . "` WHERE Key_name = 'PRIMARY'");
                        if ($stmt) {
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($row) {
                                $primaryKey = $row['Column_name'];
                            }
                        }
                    }
                    ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $col): ?>
                                    <th><?php echo htmlspecialchars($col['Field']); ?></th>
                                <?php endforeach; ?>
                                <th style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableData as $row): ?>
                                <tr>
                                    <?php foreach ($columns as $col): ?>
                                        <td>
                                            <?php 
                                            $value = $row[$col['Field']] ?? '';
                                            $valueLength = strlen($value);
                                            $isLarge = $valueLength > 50;
                                            $displayValue = $value;
                                            
                                            if ($value === null) {
                                                $displayValue = '<em style="color: #999;">NULL</em>';
                                            } elseif ($value === '') {
                                                $displayValue = '<em style="color: #999;">(empty)</em>';
                                            } else {
                                                $displayValue = htmlspecialchars($value);
                                                if ($isLarge) {
                                                    $displayValue = htmlspecialchars(substr($value, 0, 50)) . '...';
                                                }
                                            }
                                            ?>
                                            <div class="cell-content <?php echo $isLarge ? 'large-value' : ''; ?>" 
                                                 <?php if ($isLarge): ?>
                                                     data-field="<?php echo htmlspecialchars($col['Field'], ENT_QUOTES); ?>"
                                                     data-value="<?php echo base64_encode($value); ?>"
                                                     onclick="showModal(this.dataset.field, atob(this.dataset.value))"
                                                 <?php endif; ?>
                                                 title="<?php echo htmlspecialchars($row[$col['Field']] ?? ''); ?>">
                                                <?php echo $displayValue; ?>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <div style="display: flex; gap: 5px;">
                                            <a href="#" 
                                               class="btn btn-small btn-edit" 
                                               style="padding: 4px 8px; font-size: 11px;"
                                               onclick="openEditModal('<?php echo urlencode($currentTable); ?>', '<?php echo urlencode($row[$primaryKey] ?? ''); ?>', <?php echo $currentPage; ?>); return false;">✏️ Edit</a>
                                            <a href="?table=<?php echo urlencode($currentTable); ?>&action=delete&id=<?php echo urlencode($row[$primaryKey] ?? ''); ?>&page=<?php echo $currentPage; ?>" 
                                               class="btn btn-small btn-delete" 
                                               style="padding: 4px 8px; font-size: 11px; background: #dc3545;"
                                               onclick="return confirm('Are you sure you want to delete this record?');">🗑️ Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>
                            </div>
                            <div class="pagination-controls">
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=1" 
                                   class="pagination-btn <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    « First
                                </a>
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage - 1; ?>" 
                                   class="pagination-btn <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    ‹ Prev
                                </a>
                                
                                <?php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1): ?>
                                    <span class="pagination-btn disabled">...</span>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $i; ?>" 
                                       class="pagination-btn <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                    <span class="pagination-btn disabled">...</span>
                                <?php endif; ?>
                                
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $currentPage + 1; ?>" 
                                   class="pagination-btn <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    Next ›
                                </a>
                                <a href="?table=<?php echo urlencode($currentTable); ?>&page=<?php echo $totalPages; ?>" 
                                   class="pagination-btn <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    Last »
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal for large values -->
    <div id="valueModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Value</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>
    
    <!-- Modal for edit form -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 id="editModalTitle">Edit Record</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body" id="editModalBody" style="max-height: 70vh; overflow-y: auto;">
                <div style="text-align: center; padding: 20px;">
                    <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #667eea; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="margin-top: 10px;">Loading...</p>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
    
    <script>
        function showModal(fieldName, value) {
            document.getElementById('modalTitle').textContent = 'Field: ' + fieldName;
            var displayValue = value || '(empty)';
            if (value === 'null' || value === 'NULL') {
                displayValue = 'NULL';
            }
            document.getElementById('modalBody').textContent = displayValue;
            document.getElementById('valueModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('valueModal').style.display = 'none';
        }
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            var modal = document.getElementById('valueModal');
            if (event.target == modal) {
                closeModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeEditModal();
            }
        });
        
        function openEditModal(table, id, page) {
            document.getElementById('editModalTitle').textContent = 'Edit Record - ' + table;
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('editModalBody').innerHTML = '<div style="text-align: center; padding: 20px;"><div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #667eea; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px;">Loading...</p></div>';
            
            // Fetch record data
            fetch('?table=' + encodeURIComponent(table) + '&action=get_record&id=' + encodeURIComponent(id))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Get columns info (we'll need to fetch this or embed it)
                        fetch('?table=' + encodeURIComponent(table) + '&action=get_columns')
                            .then(response => response.json())
                            .then(columnsData => {
                                renderEditForm(table, data.record, data.primaryKey, columnsData.columns || [], page);
                            })
                            .catch(() => {
                                // Fallback: render form without column details
                                renderEditForm(table, data.record, data.primaryKey, [], page);
                            });
                    } else {
                        document.getElementById('editModalBody').innerHTML = '<div class="error-box">Error: ' + (data.error || 'Failed to load record') + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('editModalBody').innerHTML = '<div class="error-box">Error loading record: ' + error.message + '</div>';
                });
        }
        
        function renderEditForm(table, record, primaryKey, columns, page) {
            var formHtml = '<form id="editForm" method="POST" onsubmit="submitEditForm(event, \'' + table + '\', ' + page + ')">';
            formHtml += '<input type="hidden" name="action" value="update">';
            formHtml += '<input type="hidden" name="table" value="' + table + '">';
            formHtml += '<input type="hidden" name="id" value="' + (record[primaryKey] || '') + '">';
            formHtml += '<input type="hidden" name="page" value="' + page + '">';
            
            // If we have column info, use it; otherwise iterate over record keys
            var fields = columns.length > 0 ? columns : Object.keys(record).map(key => ({Field: key, Type: 'varchar(255)', Null: 'YES'}));
            
            fields.forEach(function(col) {
                var fieldName = col.Field || col;
                var fieldType = (col.Type || 'varchar(255)').toLowerCase();
                var isTextarea = fieldType.indexOf('text') !== -1 || fieldType.indexOf('blob') !== -1;
                var value = record[fieldName] || '';
                
                if (fieldName === primaryKey) {
                    formHtml += '<div class="form-group">';
                    formHtml += '<label>' + fieldName + ' (Primary Key)</label>';
                    formHtml += '<input type="text" value="' + escapeHtml(value) + '" disabled>';
                    formHtml += '<small>Primary key cannot be edited</small>';
                    formHtml += '</div>';
                } else {
                    formHtml += '<div class="form-group">';
                    formHtml += '<label>' + fieldName + '</label>';
                    if (isTextarea) {
                        formHtml += '<textarea name="field_' + fieldName + '" rows="4">' + escapeHtml(value) + '</textarea>';
                    } else {
                        formHtml += '<input type="text" name="field_' + fieldName + '" value="' + escapeHtml(value) + '">';
                    }
                    formHtml += '<small>Type: ' + (col.Type || 'unknown') + '</small>';
                    formHtml += '</div>';
                }
            });
            
            formHtml += '<div class="form-actions">';
            formHtml += '<button type="submit" class="btn btn-primary">💾 Save Changes</button>';
            formHtml += '<button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>';
            formHtml += '</div>';
            formHtml += '</form>';
            
            document.getElementById('editModalBody').innerHTML = formHtml;
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        function submitEditForm(event, table, page) {
            event.preventDefault();
            var form = document.getElementById('editForm');
            var formData = new FormData(form);
            
            fetch('?table=' + encodeURIComponent(table) + '&page=' + page, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Check if response is a redirect or success
                if (html.indexOf('Record updated successfully') !== -1 || html.indexOf('msg=updated') !== -1) {
                    closeEditModal();
                    window.location.reload();
                } else {
                    // Show error or response
                    document.getElementById('editModalBody').innerHTML = '<div class="error-box">Error updating record. Please try again.</div>' + form.outerHTML;
                }
            })
            .catch(error => {
                document.getElementById('editModalBody').innerHTML = '<div class="error-box">Error: ' + error.message + '</div>';
            });
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Close edit modal when clicking outside
        window.onclick = function(event) {
            var valueModal = document.getElementById('valueModal');
            var editModal = document.getElementById('editModal');
            if (event.target == valueModal) {
                closeModal();
            }
            if (event.target == editModal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>
