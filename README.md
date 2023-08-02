# Tool to split large Wordpress WXR files
Wordpress WXR XML Splitter on php

Wordpress exported WXR files are usually quite large, and cannot be imported into wordpress installation, due to **memory_limit**, **max_execution_time**, **upload_max_filesize**, **post_max_size** and other server limitation.

This script allows to split large WXR file into little chunks.

## Installation

The only file you need is wxr_xml_splitter.php

1. Download single file https://raw.githubusercontent.com/MihanEntalpo/WXR-XML-Splitter-php/main/wxr_xml_splitter.php to use it
2. Or, just clone repo `git clone https://github.com/MihanEntalpo/WXR-XML-Splitter-php.git`

## Requirements

1. PHP 7.*

## Using as a command-line script

Help:

```shell
php ./wxr_wml_splitter.php --help
```

Split source_file.xml into parts around 1000 kilobytes and name them like result_part_11_or_36.xml

```shell
php ./wxr_wml_splitter.php ./source_file.xml 1000K result_part.xml
```

Show debugging info:

```shell
php ./wxr_wml_splitter.php ./source_file.xml 1000K result_part.xml --debug
```

Split file with debugging info

```shell
php ./wxr_wml_splitter.php ./source_file.xml 1000K result_part.xml --debug
```

Split file with debugging info

```shell
php ./wxr_wml_splitter.php ./source_file.xml 1000K result_part.xml --debug
```

## Using as a library

```php
require_once "wxr_wml_splitter.php"

// setting function arguments explicitly for clarity
$src_filename = "./my_wxr_file.xml";
$size_bytes = 1024*1024*1024; // 1MB
$out_filename_format = "./output_%NUM%.xml"; // Output format should contain "%NUM%" in it
$debug = false; // to not output different debugging messages
$interactive = false; // not output information to user (used when called via console)
$force_yes = true; // do not ask yes/no (used when called via console)

//split and output files
try
{
  split_wxr_file($src_filename, $size_bytes, $out_filename_format, $debug, $interactive, $force_yes);
}
catch (NotValidWXR $e)
{
  echo "ERROR: " . $e->getMessage() . ". It seems that file is not a valid WXR file\n";
}
catch (Exception $e)
{
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
```

## How does it work

Script working by creating new files, where all the elements are the same as in the main file, except for the <item> elements.

<item> element is used for every post, page or other content type in wordpress blog, but not comments. Comments are inside of <item> element.

So, it is possible that your large WXR xml file has just single <item> with a signle post and thousands of comments (for example, if its a non approved, but not deleted SPAM coments),
and in this case file cannot be splitted.

Also, splitted files are always slightli bigger than the size, that you specify, because it compines <item>s while the needed size is achieved, and then, goes to next file.
