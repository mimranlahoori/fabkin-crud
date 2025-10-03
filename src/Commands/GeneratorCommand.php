<?php

namespace Fabkin\CrudGenerator\Commands;

use Exception;
use Fabkin\CrudGenerator\ModelGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Process\Process;

abstract class GeneratorCommand extends Command implements PromptsForMissingInput
{
    protected Filesystem $files;

    protected array $unwantedColumns = [
        'id',
        'uuid',
        'ulid',
        'password',
        'email_verified_at',
        'remember_token',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected ?string $table = null;

    protected $name = null;

    private ?array $tableColumns = null;

    protected string $modelNamespace = 'App\Models';
    protected string $controllerNamespace = 'App\Http\Controllers';
    protected string $requestNamespace = 'App\Http\Requests';
    protected string $layout = 'layouts.app';

    protected array $options = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
        $this->unwantedColumns = config('crud.model.unwantedColumns', $this->unwantedColumns);
        $this->modelNamespace = config('crud.model.namespace', $this->modelNamespace);
        $this->controllerNamespace = config('crud.controller.namespace', $this->controllerNamespace);
        $this->requestNamespace = config('crud.request.namespace', $this->requestNamespace);
        $this->layout = config('crud.layout', $this->layout);
    }

    /**
     * Generate the controller.
     */
    abstract protected function buildController(): static;

    /**
     * Generate the Model.
     */
    abstract protected function buildModel(): static;

    /**
     * Generate the views.
     */
    abstract protected function buildViews(): static;

    protected function makeDirectory(string $path): string
    {
        if (! $this->files->isDirectory(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        }

        return $path;
    }

    protected function write(string $path, string $content): void
    {
        $this->makeDirectory($path);
        $this->files->put($path, $content);
    }

    protected function getStub(string $type, bool $content = true): string
    {
        $stub_path = config('crud.stub_path', 'default');

        if (blank($stub_path) || $stub_path == 'default') {
            $stub_path = __DIR__.'/../stubs/';
        }

        $path = Str::finish($stub_path, '/').strtolower("$type.stub");

        if (! $content) {
            return $path;
        }

        return $this->files->get($path);
    }

    protected function _getControllerPath(string $name): string
    {
        return app_path($this->_getNamespacePath($this->controllerNamespace)."{$name}Controller.php");
    }

    protected function _getRequestPath(string $name): string
    {
        return app_path($this->_getNamespacePath($this->requestNamespace)."{$name}Request.php");
    }

    protected function _getModelPath(string $name): string
    {
        return $this->makeDirectory(app_path($this->_getNamespacePath($this->modelNamespace)."$name.php"));
    }

    private function _getNamespacePath(string $namespace): string
    {
        $str = Str::start(Str::finish(Str::after($namespace, 'App'), '\\'), '\\');
        return str_replace('\\', '/', $str);
    }

    protected function _getViewPath(string $view): string
    {
        $name = Str::kebab($this->name);
        $path = "/views/$name/$view.blade.php";

        return $this->makeDirectory(resource_path($path));
    }

    protected function buildReplacements(): array
    {
        return [
            '{{layout}}' => $this->layout,
            '{{modelName}}' => $this->name,
            '{{modelTitle}}' => Str::title(Str::snake($this->name, ' ')),
            '{{modelTitlePlural}}' => Str::title(Str::snake(Str::plural($this->name), ' ')),
            '{{modelNamespace}}' => $this->modelNamespace,
            '{{controllerNamespace}}' => $this->controllerNamespace,
            '{{requestNamespace}}' => $this->requestNamespace,
            '{{modelNamePluralLowerCase}}' => Str::camel(Str::plural($this->name)),
            '{{modelNamePluralUpperCase}}' => ucfirst(Str::plural($this->name)),
            '{{modelNameLowerCase}}' => Str::camel($this->name),
            '{{modelRoute}}' => $this->_getRoute(),
            '{{modelView}}' => Str::kebab($this->name),
        ];
    }

    protected function _getRoute(): string
    {
        return $this->options['route'] ?? Str::kebab(Str::plural($this->name));
    }

    protected function getField(string $title, string $column, string $type = 'form-field'): string
    {
        $replace = array_merge($this->buildReplacements(), [
            '{{title}}' => $title,
            '{{column}}' => $column,
            '{{column_snake}}' => Str::snake($column),
        ]);

        $path = "views/bootstrap/$type";

        return str_replace(
            array_keys($replace), array_values($replace), $this->getStub($path)
        );
    }

    protected function getHead(string $title): string
    {
        $replace = array_merge($this->buildReplacements(), [
            '{{title}}' => $title,
        ]);

        return str_replace(
            array_keys($replace),
            array_values($replace),
            "\t\t\t\t\t\t<th>{{title}}</th>\n"
        );
    }

    protected function getBody($column): string
    {
        $replace = array_merge($this->buildReplacements(), [
            '{{column}}' => $column,
        ]);

        return str_replace(
            array_keys($replace),
            array_values($replace),
            "\t\t\t\t\t\t<td>{{ ${{modelNameLowerCase}}->{{column}} }}</td>\n"
        );
    }

    protected function buildLayout(): void
    {
        if ($this->layout === false) {
            return;
        }

        if (view()->exists($this->layout)) {
            return;
        }

        $this->info('Creating Layout ...');

        if (! $this->requireComposerPackages(['laravel/ui'], true)) {
            throw new Exception("Unable to install laravel/ui. Please install it manually");
        }

        $this->runCommands(['php artisan ui bootstrap --auth']);
    }

    protected function getColumns(): ?array
    {
        if (empty($this->tableColumns)) {
            $this->tableColumns = Schema::getColumns($this->table);
        }

        return $this->tableColumns;
    }

    protected function getFilteredColumns(): array
    {
        $unwanted = $this->unwantedColumns;
        $columns = [];

        foreach ($this->getColumns() as $column) {
            $columns[] = $column['name'];
        }

        return array_filter($columns, fn($value) => ! in_array($value, $unwanted));
    }

    protected function modelReplacements(): array
    {
        // bootstrap case simple hi rakhte hain
        $fillable = function () {
            $filterColumns = $this->getFilteredColumns();
            array_walk($filterColumns, fn(&$value) => $value = "'".$value."'");
            return implode(', ', $filterColumns);
        };

        return [
            '{{fillable}}' => $fillable(),
        ];
    }

    protected function getNameInput(): string
    {
        return trim($this->argument('name'));
    }

    protected function buildOptions(): static
    {
        $this->options['route'] = null;
        $this->options['stack'] = 'bootstrap'; // force only bootstrap

        return $this;
    }

    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the table'],
        ];
    }

    protected function tableExists(): bool
    {
        return Schema::hasTable($this->table);
    }

    protected function requireComposerPackages(array $packages, bool $asDev = false): bool
    {
        $command = array_merge(
            ['composer', 'require'],
            $packages,
            $asDev ? ['--dev'] : [],
        );

        return (new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
                ->setTimeout(null)
                ->run(function ($type, $output) {
                    $this->output->write($output);
                }) === 0;
    }

    protected function runCommands(array $commands): void
    {
        $process = Process::fromShellCommandline(implode(' && ', $commands), null, null, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $this->output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(fn($type, $line) => $this->output->write('    '.$line));
    }
}
