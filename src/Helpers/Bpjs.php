<?php
namespace Bpjs\Framework\Helpers;

class Bpjs
{
    protected $migrationLogFile = 'database/migrations/.migrated.json';
    protected bool $running = true;
    protected $commands = [
        'init' => 'initProject',
        'make:model' => 'createModel',
        'make:controller' => 'createController',
        'make:service' => 'createService',
        'make:dto' => 'createDTO',
        'make:repo' => 'createRepo',
        'make:import' => 'createImport',
        'make:export' => 'createExport',
        'make:migration' => 'createMigration',
        'make:job' => 'createJob',
        'make:request' => 'createRequest',
        'db:migrate' => 'runMigrations',
        'db:rollback' => 'rollbackMigration',
        'db:refresh' => 'refreshMigrations',
        'db:seed' => 'runSeeder',
        'generate:key' => 'generateKey',
        'cache:route' => 'cacheRoutes',
        'cache:clear' => 'clearCache',
        'queue:work' => 'queueWork',
        'queue:retry-stuck' => 'queueRetryStuck',
        'queue:monitor' => 'queueMonitor',
        'serve' => 'Serve',
        'serve:octane' => 'serveOctane',
    ];

    public function run($argv)
    {
        $command = $argv[1] ?? null;
        $argument = $argv[2] ?? null;
        $options = array_slice($argv, 3);

        if ($command && isset($this->commands[$command])) {
            $method = $this->commands[$command];
            $this->$method($argument, $options);
        } else {
            echo "Command not found!\n\n";
            echo "Available commands:\n";
            echo str_repeat('-', 40) . "\n";
            foreach ($this->commands as $cmd => $method) {
                echo "  • {$cmd}\n";
            }
            echo str_repeat('-', 40) . "\n";
        }
    }

    protected function initProject()
    {
        $projectName = basename(getcwd());

        $envPath = BPJS_BASE_PATH . '/.env';

        if (!file_exists($envPath)) {
            copy(BPJS_BASE_PATH . '/.env.example', $envPath);
        }

        $env = file_get_contents($envPath);

        $key = base64_encode(random_bytes(32));

        $env = preg_replace('/^APP_NAME=.*/m', "APP_NAME={$projectName}", $env);
        $env = preg_replace('/^APP_URL=.*/m', "APP_URL=http://localhost/{$projectName}", $env);
        $env = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $env);

        file_put_contents($envPath, $env);

        echo " Project initialized successfully.\n";
    }

    protected function createModel($name)
    {
        if (!$name) {
            echo " Model name must be provided!\n";
            return;
        }
        
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Models';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Models/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $table = strtolower($className) . 's';
        
        $modelTemplate = "<?php\n\n";
        $modelTemplate .= "namespace {$namespace};\n\n";
        $modelTemplate .= "use Bpjs\Framework\Helpers\BaseModel;\n\n";
        $modelTemplate .= "class {$className} extends BaseModel\n";
        $modelTemplate .= "{\n";
        $modelTemplate .= "    protected string \$table = '{$table}';\n";
        $modelTemplate .= "    protected string \$primaryKey = 'id';\n";
        $modelTemplate .= "    protected array \$fillable = [];\n";
        $modelTemplate .= "    protected array \$hidden = [];\n";
        $modelTemplate .= "    public array \$timestamps = true;\n";
        $modelTemplate .= "}\n";
        
        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Model {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $modelTemplate);
            echo " Model {$className} created successfully at {$filePath}!\n";
        }
    }

    protected function createService($name)
    {
        if (!$name) {
            echo " Service name must be provided!\n";
            return;
        }
        
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Services';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Services/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $serviceTemplate = "<?php\n\n";
        $serviceTemplate .= "namespace {$namespace};\n\n";
        $serviceTemplate .= "class {$className}\n";
        $serviceTemplate .= "{\n";
        $serviceTemplate .= "    // Service logic here\n";
        $serviceTemplate .= "}\n";
        
        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Service {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $serviceTemplate);
            echo " Service {$className} created successfully!\n";
        }
    }

    protected function createDTO($name)
    {
        if (!$name) {
            echo " DTO name must be provided!\n";
            return;
        }
        
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\DTO';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/DTO/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $dtoTemplate = "<?php\n\n";
        $dtoTemplate .= "namespace {$namespace};\n\n";
        $dtoTemplate .= "final class {$className}\n";
        $dtoTemplate .= "{\n";
        $dtoTemplate .= "    public function __construct(\n";
        $dtoTemplate .= "        // Add properties here\n";
        $dtoTemplate .= "    ) {}\n";
        $dtoTemplate .= "}\n";
        
        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  DTO {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $dtoTemplate);
            echo " DTO {$namespace}\\{$className} created successfully!\n";
        }
    }

    protected function createRequest($name)
    {
        if (!$name) {
            echo " Request name must be provided!\n";
            return;
        }

        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Requests';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Requests/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $template = "<?php\n\n";
        $template .= "namespace {$namespace};\n\n";
        $template .= "use Bpjs\Framework\Core\FormRequest;\n\n";
        $template .= "class {$className} extends FormRequest\n";
        $template .= "{\n";
        $template .= "    public function authorize(): bool\n";
        $template .= "    {\n";
        $template .= "        return true;\n";
        $template .= "    }\n\n";
        $template .= "    public function rules(): array\n";
        $template .= "    {\n";
        $template .= "        return [\n";
        $template .= "            //\n";
        $template .= "        ];\n";
        $template .= "    }\n\n";
        $template .= "    public function messages(): array\n";
        $template .= "    {\n";
        $template .= "        return [\n";
        $template .= "            //\n";
        $template .= "        ];\n";
        $template .= "    }\n";
        $template .= "}\n";

        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Request {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $template);
            echo " Request {$className} created successfully!\n";
        }
    }

    protected function createRepo($name)
    {
        if (!$name) {
            echo " Repository name must be provided!\n";
            return;
        }
        
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Repositories';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Repositories/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $repoTemplate = "<?php\n\n";
        $repoTemplate .= "namespace {$namespace};\n\n";
        $repoTemplate .= "class {$className}\n";
        $repoTemplate .= "{\n";
        $repoTemplate .= "    // Repository logic here\n";
        $repoTemplate .= "}\n";
        
        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Repository {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $repoTemplate);
            echo " Repository {$className} created successfully!\n";
        }
    }

    protected function createImport($name)
    {
        if (!$name) {
            echo " Import name must be provided!\n";
            return;
        }
        
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Imports';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Imports/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $importTemplate = "<?php\n\n";
        $importTemplate .= "namespace {$namespace};\n\n";
        $importTemplate .= "use Bpjs\Framework\Helpers\Importer;\n\n";
        $importTemplate .= "class {$className} extends Importer\n";
        $importTemplate .= "{\n";
        $importTemplate .= "    // Import logic here\n";
        $importTemplate .= "}\n";
        
        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Import {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $importTemplate);
            echo " Import {$className} created successfully!\n";
        }
    }

    protected function createExport($name)
    {
        if (!$name) {
            echo " Export name must be provided!\n";
            return;
        }
        
        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Exports';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Exports/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $exportTemplate = "<?php\n\n";
        $exportTemplate .= "namespace {$namespace};\n\n";
        $exportTemplate .= "class {$className}\n";
        $exportTemplate .= "{\n";
        $exportTemplate .= "    // Export logic here\n";
        $exportTemplate .= "}\n";
        
        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Export {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $exportTemplate);
            echo " Export {$className} created successfully!\n";
        }
    }

    protected function createController($name, $options = [])
    {
        if (!$name) {
            echo " Controller name must be provided!\n";
            return;
        }

        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Controllers';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Controllers/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $isResource = in_array('--resource', $options);
        
        $controllerTemplate = "<?php\n\n";
        $controllerTemplate .= "namespace {$namespace};\n\n";
        $controllerTemplate .= "use Bpjs\Framework\Helpers\BaseController;\n";
        $controllerTemplate .= "use Bpjs\Framework\Core\Request;\n";
        $controllerTemplate .= "use Bpjs\Framework\Helpers\View;\n\n";
        $controllerTemplate .= "class {$className} extends BaseController\n";
        $controllerTemplate .= "{\n";
        
        if ($isResource) {
            $controllerTemplate .= "    public function index()\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Display all resources\n";
            $controllerTemplate .= "    }\n\n";
            
            $controllerTemplate .= "    public function create()\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Display create form\n";
            $controllerTemplate .= "    }\n\n";
            
            $controllerTemplate .= "    public function store(Request \$request)\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Store new resource\n";
            $controllerTemplate .= "    }\n\n";
            
            $controllerTemplate .= "    public function show(\$id)\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Display resource with ID: \$id\n";
            $controllerTemplate .= "    }\n\n";
            
            $controllerTemplate .= "    public function edit(\$id)\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Display edit form\n";
            $controllerTemplate .= "    }\n\n";
            
            $controllerTemplate .= "    public function update(Request \$request, \$id)\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Update resource with ID: \$id\n";
            $controllerTemplate .= "    }\n\n";
            
            $controllerTemplate .= "    public function destroy(\$id)\n";
            $controllerTemplate .= "    {\n";
            $controllerTemplate .= "        // Delete resource with ID: \$id\n";
            $controllerTemplate .= "    }\n";
        } else {
            $controllerTemplate .= "    // Controller logic here\n";
        }
        
        $controllerTemplate .= "}\n";

        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Controller {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $controllerTemplate);
            echo " Controller {$className} created successfully at {$filePath}!\n";
        }
    }

    protected function createJob($name)
    {
        if (!$name) {
            echo " Job name must be provided!\n";
            return;
        }

        $parts = explode('/', $name);
        $className = array_pop($parts);
        $namespace = 'App\\Jobs';
        if (!empty($parts)) {
            $namespace .= '\\' . implode('\\', $parts);
        }
        $directory = 'app/Jobs/' . implode('/', $parts);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $jobTemplate = "<?php\n\n";
        $jobTemplate .= "namespace {$namespace};\n\n";
        $jobTemplate .= "class {$className}\n";
        $jobTemplate .= "{\n";
        $jobTemplate .= "    public function handle(array \$data): void\n";
        $jobTemplate .= "    {\n";
        $jobTemplate .= "        // Job logic here\n";
        $jobTemplate .= "    }\n";
        $jobTemplate .= "}\n";

        $filePath = "{$directory}/{$className}.php";
        
        if (file_exists($filePath)) {
            echo "  Job {$className} already exists!\n";
        } else {
            file_put_contents($filePath, $jobTemplate);
            echo " Job {$className} created successfully at {$filePath}!\n";
        }
    }

    protected function createMigration($name)
    {
        if (!$name) {
            echo " Migration name must be provided!\n";
            return;
        }

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_{$name}.php";
        $filePath = "database/migrations/{$fileName}";

        if (!is_dir('database/migrations')) {
            mkdir('database/migrations', 0777, true);
        }

        // Detect migration type and table
        $table = 'unknown';
        $type = 'create';
        
        if (preg_match('/create_(.*?)_table/', $name, $matches)) {
            $table = $matches[1];
            $type = 'create';
        } elseif (preg_match('/add_(.*?)_to_(.*?)_table/', $name, $matches)) {
            $table = $matches[2];
            $type = 'add';
        } elseif (preg_match('/alter_(.*?)_table/', $name, $matches)) {
            $table = $matches[1];
            $type = 'alter';
        } elseif (preg_match('/drop_(.*?)_table/', $name, $matches)) {
            $table = $matches[1];
            $type = 'drop';
        }

        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));

        // Migration template based on type
        if ($type === 'create') {
            $migrationTemplate = $this->getCreateMigrationTemplate($className, $table);
        } elseif ($type === 'add') {
            $migrationTemplate = $this->getAddMigrationTemplate($className, $table);
        } elseif ($type === 'alter') {
            $migrationTemplate = $this->getAlterMigrationTemplate($className, $table);
        } elseif ($type === 'drop') {
            $migrationTemplate = $this->getDropMigrationTemplate($className, $table);
        } else {
            $migrationTemplate = $this->getDefaultMigrationTemplate($className, $table);
        }

        file_put_contents($filePath, $migrationTemplate);
        echo " Migration {$fileName} created successfully!\n";
        echo "    Location: {$filePath}\n";
        echo "    Table: {$table}\n";
        echo "     Type: {$type}\n";
    }

    protected function getCreateMigrationTemplate($className, $table)
    {
        $template = "<?php\n\n";
        $template .= "use Bpjs\Framework\Helpers\SchemaBuilder;\n";
        $template .= "use Bpjs\Framework\Helpers\Database;\n\n";
        $template .= "class {$className}\n";
        $template .= "{\n";
        $template .= "    public function up(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        //  Define columns here\n";
        $template .= "        \$table->id();\n";
        $template .= "        \$table->string('name')->notNullable();\n";
        $template .= "        \$table->string('slug')->unique();\n";
        $template .= "        \$table->text('description')->nullable();\n";
        $template .= "        \$table->boolean('is_active')->default(1);\n";
        $template .= "        \$table->timestamps();\n";
        $template .= "        \$table->softDeletes();\n";
        $template .= "        \n";
        $template .= "        //  Add indexes\n";
        $template .= "        \$table->index(['name', 'slug']);\n";
        $template .= "        \n";
        $template .= "        \$sql = \$table->buildCreateSQL();\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            \$pdo->exec(\$sql);\n";
        $template .= "            echo \" Table '{$table}' created successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to create table: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "            echo \" SQL: \" . \$sql . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n\n";
        $template .= "    public function down(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            \$pdo->exec(\$table->buildDropSQL());\n";
        $template .= "            echo \" Table '{$table}' dropped successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to drop table: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n";
        $template .= "}\n";
        
        return $template;
    }

    protected function getAddMigrationTemplate($className, $table)
    {
        $template = "<?php\n\n";
        $template .= "use Bpjs\Framework\Helpers\SchemaBuilder;\n";
        $template .= "use Bpjs\Framework\Helpers\Database;\n\n";
        $template .= "class {$className}\n";
        $template .= "{\n";
        $template .= "    public function up(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        //  Add columns here\n";
        $template .= "        \$column = \$table->string('new_column')->nullable();\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            \$pdo->exec(\$table->buildAddColumnSQL(\$column));\n";
        $template .= "            echo \" Column added to table '{$table}' successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to add column: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n\n";
        $template .= "    public function down(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            \$pdo->exec(\$table->buildDropColumnSQL('new_column'));\n";
        $template .= "            echo \" Column removed from table '{$table}' successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to remove column: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n";
        $template .= "}\n";
        
        return $template;
    }

    protected function getAlterMigrationTemplate($className, $table)
    {
        $template = "<?php\n\n";
        $template .= "use Bpjs\Framework\Helpers\SchemaBuilder;\n";
        $template .= "use Bpjs\Framework\Helpers\Database;\n\n";
        $template .= "class {$className}\n";
        $template .= "{\n";
        $template .= "    public function up(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        //  Modify columns here\n";
        $template .= "        // \$table->buildModifyColumnSQL(\$column);\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            // \$pdo->exec(\$sql);\n";
        $template .= "            echo \" Table '{$table}' modified successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to modify table: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n\n";
        $template .= "    public function down(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            // Rollback logic here\n";
        $template .= "            echo \" Rollback for table '{$table}' completed successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to rollback: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n";
        $template .= "}\n";
        
        return $template;
    }

    protected function getDropMigrationTemplate($className, $table)
    {
        $template = "<?php\n\n";
        $template .= "use Bpjs\Framework\Helpers\SchemaBuilder;\n";
        $template .= "use Bpjs\Framework\Helpers\Database;\n\n";
        $template .= "class {$className}\n";
        $template .= "{\n";
        $template .= "    public function up(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            \$pdo->exec(\$table->buildDropSQL());\n";
        $template .= "            echo \" Table '{$table}' dropped successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Failed to drop table: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n\n";
        $template .= "    public function down(): void\n";
        $template .= "    {\n";
        $template .= "        // Cannot rollback drop table\n";
        $template .= "        echo \"  Cannot rollback drop table\\n\";\n";
        $template .= "    }\n";
        $template .= "}\n";
        
        return $template;
    }

    protected function getDefaultMigrationTemplate($className, $table)
    {
        $template = "<?php\n\n";
        $template .= "use Bpjs\Framework\Helpers\SchemaBuilder;\n";
        $template .= "use Bpjs\Framework\Helpers\Database;\n\n";
        $template .= "class {$className}\n";
        $template .= "{\n";
        $template .= "    public function up(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        //  Migration logic here\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            // \$pdo->exec(\$sql);\n";
        $template .= "            echo \" Migration '{$className}' completed successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Migration failed: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n\n";
        $template .= "    public function down(): void\n";
        $template .= "    {\n";
        $template .= "        \$pdo = Database::connection();\n";
        $template .= "        \$table = new SchemaBuilder('{$table}');\n";
        $template .= "        \n";
        $template .= "        try {\n";
        $template .= "            // Rollback logic here\n";
        $template .= "            echo \" Rollback for '{$className}' completed successfully\\n\";\n";
        $template .= "        } catch (\\PDOException \$e) {\n";
        $template .= "            echo \" Rollback failed: \" . \$e->getMessage() . \"\\n\";\n";
        $template .= "        }\n";
        $template .= "    }\n";
        $template .= "}\n";
        
        return $template;
    }

    protected function runMigrations()
    {
        $migrationPath = 'database/migrations';
        if (!is_dir($migrationPath)) {
            echo " Migration folder not found.\n";
            return;
        }

        $migrated = $this->getMigrationLog() ?? [];
        $files = scandir($migrationPath);
        $files = array_filter($files, fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'php');
        sort($files);

        if (empty($files)) {
            echo "  No migration files found.\n";
            return;
        }

        // Use Database helper
        $pdo = Database::connection();

        echo "\n Running migrations...\n";
        echo str_repeat('=', 60) . "\n";

        $executed = 0;

        foreach ($files as $file) {
            if (in_array($file, $migrated)) {
                echo "  Skip {$file} (already executed)\n";
                continue;
            }

            require_once "$migrationPath/$file";
            $className = $this->getClassNameFromFile($file);

            if (!class_exists($className)) {
                echo " Class {$className} not found in {$file}\n";
                continue;
            }

            $migration = new $className();

            if (!method_exists($migration, 'up')) {
                echo "  Method up() not found in {$className}, skipping.\n";
                continue;
            }

            echo " Running: {$className}...\n";

            try {
                $pdo->beginTransaction();
                $migration->up();
                $pdo->commit();

                $this->logMigration($file);
                $executed++;
                echo " {$file} executed successfully.\n";
            } catch (\PDOException $e) {
                $pdo->rollBack();
                echo " Error on migration {$file}:\n";
                echo "    " . $e->getMessage() . "\n";
                
                echo "   Continue with next migration? (y/n): ";
                $handle = fopen("php://stdin", "r");
                $line = trim(fgets($handle));
                fclose($handle);
                
                if (strtolower($line) !== 'y') {
                    echo " Migration stopped.\n";
                    break;
                }
            } catch (\Exception $e) {
                $pdo->rollBack();
                echo " Error on migration {$file}: " . $e->getMessage() . "\n";
                break;
            }
        }

        echo str_repeat('=', 60) . "\n";
        echo " Done! {$executed} migration(s) executed successfully.\n\n";
    }

    protected function getClassNameFromFile($file)
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    protected function logMigration(string $file)
    {
        $logPath = 'database/migrations/.migrated.json';
        $migrated = file_exists($logPath) ? json_decode(file_get_contents($logPath), true) : [];
        $migrated = array_filter($migrated, fn($f) => $f !== $file);
        $migrated[] = $file;
        file_put_contents($logPath, json_encode(array_values($migrated), JSON_PRETTY_PRINT));
    }

    protected function removeLastMigration()
    {
        $data = $this->getMigrationLog();
        array_pop($data);
        file_put_contents($this->migrationLogFile, json_encode(array_values($data), JSON_PRETTY_PRINT));
    }

    protected function getMigrationLog()
    {
        if (!file_exists($this->migrationLogFile)) {
            file_put_contents($this->migrationLogFile, json_encode([]));
            return [];
        }

        $content = file_get_contents($this->migrationLogFile);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    protected function rollbackMigration()
    {
        $migrated = $this->getMigrationLog();
        if (empty($migrated)) {
            echo "  No migrations to rollback.\n";
            return;
        }

        $lastFile = array_pop($migrated);
        $path = "database/migrations/{$lastFile}";

        if (!file_exists($path)) {
            echo " Migration file {$lastFile} not found.\n";
            return;
        }

        require_once $path;
        $className = $this->getClassNameFromFile($lastFile);

        $pdo = Database::connection();

        if (!class_exists($className)) {
            echo " Class {$className} not found in {$lastFile}.\n";
            return;
        }

        $migration = new $className();

        if (!method_exists($migration, 'down')) {
            echo "  Method down() not found in {$className}.\n";
            return;
        }

        echo " Rolling back: {$className}...\n";

        try {
            $pdo->beginTransaction();
            $migration->down();
            $pdo->commit();

            $this->removeLastMigration();
            echo " Rollback {$lastFile} completed successfully.\n";
        } catch (\PDOException $e) {
            $pdo->rollBack();
            echo " Rollback failed: " . $e->getMessage() . "\n";
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo " Rollback failed: " . $e->getMessage() . "\n";
        }
    }

    protected function refreshMigrations()
    {
        echo "\n Refreshing migrations...\n";
        echo str_repeat('=', 60) . "\n";

        $migrated = $this->getMigrationLog();
        while (!empty($migrated)) {
            $this->rollbackMigration();
            $migrated = $this->getMigrationLog();
        }

        echo "\n Running migrations again...\n";
        $this->runMigrations();
    }

    protected function runSeeder($name = null)
    {
        $seederPath = 'database/seeders';
        if (!is_dir($seederPath)) {
            echo " Seeder folder not found.\n";
            return;
        }

        if ($name) {
            $file = "{$seederPath}/{$name}.php";
            if (!file_exists($file)) {
                echo " Seeder {$name} not found.\n";
                return;
            }
            require_once $file;
            
            if (!class_exists($name)) {
                echo " Class {$name} not found.\n";
                return;
            }

            $seeder = new $name();
            if (method_exists($seeder, 'run')) {
                echo " Running seeder: {$name}...\n";
                $seeder->run();
                echo " Seeder {$name} completed.\n";
            } else {
                echo "  Method run() not found in {$name}.\n";
            }
        } else {
            $files = scandir($seederPath);
            $files = array_filter($files, fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'php');
            
            if (empty($files)) {
                echo "  No seeders found.\n";
                return;
            }

            echo " Running all seeders...\n";
            foreach ($files as $file) {
                $className = pathinfo($file, PATHINFO_FILENAME);
                require_once "{$seederPath}/{$file}";
                
                if (class_exists($className)) {
                    $seeder = new $className();
                    if (method_exists($seeder, 'run')) {
                        echo "    {$className}...\n";
                        $seeder->run();
                        echo "    {$className} completed.\n";
                    }
                }
            }
            echo " All seeders completed.\n";
        }
    }

    protected function generateKey()
    {
        $newKey = 'base64:' . base64_encode(random_bytes(32));
        $envPath = BPJS_BASE_PATH . '/.env';

        if (!file_exists($envPath)) {
            file_put_contents($envPath, "APP_KEY={$newKey}\n");
            echo " .env created with new APP_KEY successfully.\n";
            return;
        }

        $env = file_get_contents($envPath);

        if (strpos($env, 'APP_KEY=') === false) {
            $env .= PHP_EOL . "APP_KEY={$newKey}" . PHP_EOL;
        } else {
            $env = preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$newKey}", $env);
        }

        file_put_contents($envPath, $env);

        echo " APP_KEY generated successfully.\n";
    }

    protected function cacheRoutes()
    {
        echo " Generating route cache...\n";

        require_once BPJS_BASE_PATH . '/vendor/autoload.php';

        $app = require BPJS_BASE_PATH . '/bootstrap/app.php';

        \Bpjs\Framework\Helpers\Route::init('');
        require BPJS_BASE_PATH . '/routes/web.php';

        \Bpjs\Framework\Helpers\Api::init('/api');
        require BPJS_BASE_PATH . '/routes/api.php';

        $data = [
            'web' => \Bpjs\Framework\Helpers\Route::export(),
            'web_names' => \Bpjs\Framework\Helpers\Route::exportNames(),
            'api' => \Bpjs\Framework\Helpers\Api::export(),
            'api_names' => \Bpjs\Framework\Helpers\Api::exportNames(),
        ];

        $path = BPJS_BASE_PATH . '/storage/cache/routes.php';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, '<?php return ' . var_export($data, true) . ';');

        echo " Route cache created successfully!\n";
    }

    protected function clearCache()
    {
        $cacheDir = BPJS_BASE_PATH . '/storage/cache';

        if (!is_dir($cacheDir)) {
            echo "  Cache directory not found.\n";
            return;
        }

        $files = glob($cacheDir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        echo " Cache cleared successfully!\n";
    }

    protected function Serve()
    {
        $host = '127.0.0.1';
        $port = '8000';

        global $argv;
        foreach ($argv as $arg) {
            if (strpos($arg, '--host=') !== false) {
                $host = substr($arg, 7);
            }
            if (strpos($arg, '--port=') !== false) {
                $port = substr($arg, 7);
            }
        }
        
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            echo " Error: Invalid host address provided: $host\n";
            exit(1);
        }

        if (!is_numeric($port) || (int) $port < 1024 || (int) $port > 65535) {
            echo " Error: Invalid port number provided: $port\n";
            exit(1);
        }

        echo " Starting development server on http://{$host}:{$port}\n";
        echo "Press Ctrl+C to stop\n\n";
        exec("php -S {$host}:{$port}");
    }

    protected function serveOctane()
    {
        echo " Starting RoadRunner (Octane Mode)...\n";
        exec("rr serve");
    }

    public function stopWorker()
    {
        $this->running = false;
    }

    protected function queueWork($queue = 'default')
    {
        $queues = explode(',', $queue);
        $sleep = (int) env('QUEUE_SLEEP', 2);
        $maxTries = (int) env('QUEUE_TRIES', 3);
        $memoryLimit = env('QUEUE_MEMORY', 128) * 1024 * 1024;
        $keepAliveInterval = (int) env('QUEUE_KEEPALIVE', 300);
        $lastKeepAlive = time();

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->stopWorker());
            pcntl_signal(SIGINT, fn() => $this->stopWorker());
        }

        echo "\n Starting Queue Worker...\n";
        echo str_repeat('=', 60) . "\n";
        echo " Listening queues: " . implode(', ', $queues) . "\n";
        echo " Max attempts: {$maxTries}\n";
        echo "  Keep-alive interval: {$keepAliveInterval}s\n\n";

        if (\Bpjs\Framework\Helpers\Queue::engine() === \Bpjs\Framework\Helpers\Queue::ENGINE_DATABASE) {
            try {
                \Bpjs\Framework\Helpers\Queue::installMigration();
            } catch (Throwable $e) {
                echo "  Warning: " . $e->getMessage() . "\n";
            }
        }

        while ($this->running) {
            try {
                if ((time() - $lastKeepAlive) > $keepAliveInterval) {
                    try {
                        $db = \Bpjs\Framework\Helpers\Database::connection();
                        $db->query('SELECT 1');
                    } catch (Throwable $e) {
                        \Bpjs\Framework\Helpers\Database::disconnect();
                        \Bpjs\Framework\Helpers\Database::connection();
                    }
                    $lastKeepAlive = time();
                }

                $job = null;

                foreach ($queues as $q) {
                    $q = trim($q);
                    
                    try {
                        $job = \Bpjs\Framework\Helpers\Queue::pop($q);
                        if ($job) {
                            break;
                        }
                    } catch (Throwable $e) {
                        if (str_contains($e->getMessage(), 'server has gone away') ||
                            str_contains($e->getMessage(), 'lost connection')) {
                            \Bpjs\Framework\Helpers\Database::disconnect();
                            continue;
                        }
                        throw $e;
                    }
                }

                if (!$job) {
                    sleep($sleep);
                    continue;
                }

                $start = microtime(true);
                echo " [JOB] Processing ID {$job->id} | Queue: {$job->queue} | Attempt: {$job->attempts}/{$maxTries}\n";

                $payload = json_decode($job->payload, true);
                $class = $payload['job'] ?? null;
                $data = $payload['data'] ?? [];
                $method = $payload['method'] ?? 'handle';

                if (!$class) {
                    throw new \Exception("Job class empty.");
                }

                if (!class_exists($class)) {
                    throw new \Exception("Job class not found: {$class}");
                }

                $instance = new $class();

                if (!method_exists($instance, $method)) {
                    throw new \Exception("Method {$method} not found in {$class}");
                }

                $instance->$method($data);
                \Bpjs\Framework\Helpers\Queue::done($job->id);

                $duration = round(microtime(true) - $start, 3);
                echo " [DONE] Job {$job->id} in {$duration}s\n";

            } catch (Throwable $e) {
                if (isset($job) && $job) {
                    try {
                        if ($job->attempts < $maxTries) {
                            \Bpjs\Framework\Helpers\Queue::release($job->id);
                            echo " [RETRY] Job {$job->id} attempt {$job->attempts}/{$maxTries}\n";
                        } else {
                            \Bpjs\Framework\Helpers\Queue::fail($job->id, $e->getMessage());
                            echo " [FAILED] Job {$job->id} - {$e->getMessage()}\n";
                        }
                    } catch (Throwable $e2) {
                        echo " [ERROR] Failed to process job: " . $e2->getMessage() . "\n";
                    }
                }

                echo " [ERROR] " . $e->getMessage() . "\n";
                sleep($sleep);
            }

            if (memory_get_usage(true) > $memoryLimit) {
                echo " [STOP] Memory limit exceeded. Restarting worker...\n";
                exit(0);
            }
        }

        echo " Worker stopped gracefully.\n";
    }

    protected function queueRetryStuck()
    {
        $queues = ['default'];
        $total = 0;
        
        echo "\n Retry stuck jobs...\n";
        echo str_repeat('=', 60) . "\n";
        
        foreach ($queues as $queue) {
            try {
                $count = \Bpjs\Framework\Helpers\Queue::retryStuck($queue);
                $total += $count;
                if ($count > 0) {
                    echo " Retried {$count} stuck jobs from '{$queue}' queue\n";
                } else {
                    echo "  No stuck jobs in '{$queue}' queue\n";
                }
            } catch (\Exception $e) {
                echo " Error on queue '{$queue}': " . $e->getMessage() . "\n";
            }
        }
        
        echo str_repeat('=', 60) . "\n";
        echo " Total retried: {$total} jobs\n\n";
    }

    protected function queueMonitor()
    {
        $queues = ['default'];
        
        echo "\n Queue Status:\n";
        echo str_repeat('=', 60) . "\n";
        
        foreach ($queues as $queue) {
            try {
                $size = \Bpjs\Framework\Helpers\Queue::size($queue);
                $total = array_sum($size);
                
                echo " Queue: {$queue}\n";
                echo "    Pending:    {$size['pending']}\n";
                echo "    Processing: {$size['processing']}\n";
                echo "    Done:       {$size['done']}\n";
                echo "    Failed:     {$size['failed']}\n";
                echo "    Total:      {$total}\n";
                
                if ($total > 100) {
                    echo "     WARNING: Queue size > 100!\n";
                }
                echo str_repeat('-', 60) . "\n";
            } catch (\Exception $e) {
                echo " Error on queue '{$queue}': " . $e->getMessage() . "\n";
            }
        }
        echo "\n";
    }
}