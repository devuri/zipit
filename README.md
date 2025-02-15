# ZipIt

**ZipIt** is a simple, flexible PHP CLI tool for creating zip archives, providing features like progress bars, customizable output locations, and recursive file archiving. It now includes a `copy` command to duplicate files instead of zipping them.

## Features

- **Standalone Executable**: ZipIt is now a fully compiled executable file, which can be stored in your project (e.g., `bin/zipit`).
- **Configurable**: Define the base directory, files to include, and exclusions in a `.zipit-conf.php` file.
- **Customizable Output**: Optionally specify the output file name and path in the configuration file.
- **Recursive Archiving**: Automatically includes directories and their contents.
- **Styled Output**: Uses color-coded messages for warnings, errors, and success feedback.
- **Progress Bar**: Visual progress for long-running operations.
- **Custom Config Path**: Option to specify a custom configuration file path.
- **Copy Command**: Use `bin/zipit copy` to copy files instead of zipping them.

## Installation

Download the `zipit` executable and place it in your project directory, for example:

```bash
mv zipit bin/zipit
chmod +x bin/zipit
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
    'outputDir' => __DIR__ . '/build', // Optional: Custom output directory path
    'outputFile' => 'project-archive.zip', // Optional: Custom output file name
];
```

### Configuration Details

- **baseDir**: The root directory for all files to be zipped. Paths in `files` and `exclude` are relative to this directory.
- **files**: Array of files and directories to include in the zip archive.
- **exclude**: Array of files and directories to exclude. Paths are also relative to `baseDir`.
- **outputDir**: (Optional) Specify a custom directory for the output. If not provided, the timestamp will be used.
- **outputFile**: (Optional) Specify a custom name for the output zip file. If not provided, the file name `project-archive-{timestamp}` will be used.

## Usage

After setting up the `.zipit-conf.php` file, use the `zipit` command to create a zip archive.

### Running ZipIt

Run **ZipIt** from your project’s root directory:

```bash
bin/zipit output.zip
```

- **output.zip**: The name of the zip file to create. If `outputFile` is set in the configuration file, that path will override this argument.

### Specifying a Custom Config File

You can specify a custom configuration file path:

```bash
bin/zipit output.zip /path/to/.zipit-conf.php
```

### Copy Command

If you want to copy files instead of zipping them, use the `copy` command:

```bash
bin/zipit copy source_file destination_file
```

This will copy `source_file` to `destination_file`.

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
    'outputFile' => __DIR__ . '/project-archive.zip', // Optional: Custom output file name
];
```

Running `bin/zipit archive.zip` will create `project-archive.zip` in the project root if `outputFile` is set. Otherwise, it will create `archive.zip` with `file1.txt`, `file2.txt`, and `directory1/file3.txt`, while excluding `directory1/exclude-this.txt`.

## Output

- **Styled Messages**: Errors, warnings, and notes are shown in color for easy readability.
- **Progress Bar**: Tracks the zipping process to keep you informed.

## Requirements

- PHP  8.1 or higher

## License

This project is licensed under the MIT License. See the LICENSE file for details.

Enjoy easy archiving with **ZipIt**!

