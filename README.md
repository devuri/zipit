# ZipIt

**ZipIt** is a simple, flexible PHP CLI tool for creating zip archives, providing features like progress bars and recursive file archiving.

## Features

- **Configurable**: Define the base directory, files to include, and exclusions in a `.zipit-conf.php` file.
- **Recursive Archiving**: Automatically includes directories and their contents.
- **Styled Output**: Uses color-coded messages for warnings, errors, and success feedback.
- **Progress Bar**: Visual progress for long-running operations.
- **Custom Config Path**: Option to specify a custom configuration file path.

## Installation

Add **ZipIt** to your project with Composer:

```bash
composer require devuri/zipit
```

## Configuration

Create a `.zipit-conf.php` file in your project root directory. This file should return an array with the following configuration keys:

```php
<?php

return [
    'baseDir' => __DIR__, // The base directory where files are located
    'files' => [          // List of files and directories to include
        'file1.txt',
        'directory1',
        'file2.txt',
    ],
    'exclude' => [        // List of files and directories to exclude
        'directory1/exclude-this.txt',
        'file-to-exclude.txt',
    ],
];
```

### Configuration Details

- **baseDir**: The root directory for all files to be zipped. Paths in `files` and `exclude` are relative to this directory.
- **files**: Array of files and directories to include in the zip archive.
- **exclude**: Array of files and directories to exclude. Paths are also relative to `baseDir`.

## Usage

After setting up the `.zipit-conf.php` file, use the `zipit` command to create a zip archive. The `zipit` executable will be available in `vendor/bin` after installation.

### Running ZipIt

Run **ZipIt** from your project’s root directory:

```bash
vendor/bin/zipit output.zip
```

- **output.zip**: The name of the zip file to create.

### Specifying a Custom Config File

You can specify a custom configuration file path:

```bash
vendor/bin/zipit output.zip /path/to/.zipit-conf.php
```

### Example

Suppose you have the following directory structure:

```
/my-project
  |-- file1.txt
  |-- file2.txt
  |-- /directory1
      |-- file3.txt
      |-- exclude-this.txt
  |-- .zipit-conf.php
```

In `.zipit-conf.php`:

```php
<?php

return [
    'baseDir' => __DIR__,
    'files' => [
        'file1.txt',
        'file2.txt',
        'directory1',
    ],
    'exclude' => [
        'directory1/exclude-this.txt',
    ],
];
```

Running `vendor/bin/zipit archive.zip` will create `archive.zip` with `file1.txt`, `file2.txt`, and `directory1/file3.txt`, but it will exclude `directory1/exclude-this.txt`.

## Output

- **Styled Messages**: Errors, warnings, and notes are shown in color for easy readability.
- **Progress Bar**: Tracks the zipping process to keep you informed.

## Requirements

- PHP 7.4 or higher
- Composer

## License

This project is licensed under the MIT License. See the LICENSE file for details.

Enjoy easy archiving with **ZipIt**!
