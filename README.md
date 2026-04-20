# ZipIt

**ZipIt** is a simple, flexible PHP CLI tool for creating zip archives and copying build files. It features progress bars, customizable output locations, recursive file archiving, and file remapping — so files can be stored under one path locally but land at a different path in the output.

## Features

- **Standalone Executable**: ZipIt is a fully compiled executable, ready to drop into your project (e.g., `bin/zipit`).
- **Configurable**: Define the base directory, files to include, and exclusions in a `.zipit-conf.php` file.
- **File Remapping**: Map a source file to a different destination path in the output using `'source' => 'dest'` syntax.
- **Customizable Output**: Optionally specify the output file name and path in the configuration file.
- **Recursive Archiving**: Automatically includes directories and their contents.
- **Styled Output**: Color-coded messages for warnings, errors, and success feedback.
- **Progress Bar**: Visual progress tracking for long-running operations.
- **Custom Config Path**: Optionally specify a configuration file path as a CLI argument.
- **Copy Command**: Use `bin/zipit copy` to copy files to a directory instead of zipping them.

## Installation

Download the `zipit` executable and place it in your project:

```bash
mv zipit bin/zipit
chmod +x bin/zipit
```

## Configuration

Create a `.zipit-conf.php` file in your project root. This file must return an array with the following keys:

```php
<?php
return [
    'baseDir'    => __DIR__,
    'files'      => [
        'file1.txt',
        'directory1',
        'subdirectory/file2.txt',
    ],
    'exclude'    => [
        'directory1/exclude-this.txt',
    ],
    'outputDir'  => __DIR__ . '/build',
    'outputFile' => 'project-archive.zip',
];
```

### Configuration Keys

| Key | Required | Description |
|---|---|---|
| `baseDir` | Yes | Root directory for all source paths. All paths in `files` and `exclude` are relative to this. |
| `files` | Yes | Files and directories to include. Supports plain strings and `source => dest` remapping (see below). |
| `exclude` | No | Files and directories to exclude. Paths are relative to `baseDir`. |
| `outputDir` | No | Output directory. Defaults to a timestamped directory if not set. |
| `outputFile` | No | Output filename. Defaults to `project-archive-{timestamp}.zip` if not set. |

### File Remapping

By default, every entry in `files` preserves its path relative to `baseDir` in the output. If you need a file to land at a **different path** in the output, use `'source' => 'destination'` syntax:

```php
'files' => [
    'index.php',
    'src',
    'assets/dist/styles.css' => 'styles.css',
    'config/defaults.php'    => 'config.php',
],
```

Plain string entries and remapped entries can be mixed freely. Remapping only applies to individual files; directories always recurse using their natural relative path.

## Usage

Run **ZipIt** from your project root. It will look for `.zipit-conf.php` in the current directory by default, or you can pass a path explicitly:

```bash
# Use config in current directory
bin/zipit

# Use a config file at a specific path
bin/zipit /path/to/.zipit-conf.php
```

### Copy Command

To copy files to a directory instead of creating a zip archive:

```bash
bin/zipit copy

# With explicit config path
bin/zipit copy /path/to/.zipit-conf.php
```

The `copy` command uses the same `.zipit-conf.php` configuration, including file remapping.

## Example

Given this directory structure:

```
/my-project
  |-- index.php
  |-- readme.txt
  |-- src/
  |-- assets/
  |   |-- dist/
  |       |-- styles.css
  |-- directory1/
  |   |-- file3.txt
  |   |-- exclude-this.txt
  |-- .zipit-conf.php
```

With this `.zipit-conf.php`:

```php
<?php
return [
    'baseDir'    => __DIR__,
    'files'      => [
        'index.php',
        'readme.txt',
        'src',
        'directory1',
        'assets/dist/styles.css' => 'styles.css',
    ],
    'exclude'    => [
        'directory1/exclude-this.txt',
    ],
    'outputDir'  => __DIR__ . '/build',
    'outputFile' => 'my-project.zip',
];
```

Running `bin/zipit` will produce `build/my-project.zip` containing:

```
index.php
readme.txt
src/
directory1/file3.txt        ← exclude-this.txt is omitted
styles.css                  ← remapped from assets/dist/styles.css
```

## Output

On completion, ZipIt prints a summary including the full list of files processed, total file count, total size, and the output location. Warnings are shown for any configured files that could not be found — and the command exits with a non-zero status if any entries were missing, making it safe to use in CI pipelines.

## Requirements

- PHP 8.1 or higher

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---

Enjoy easy archiving with **ZipIt**!
