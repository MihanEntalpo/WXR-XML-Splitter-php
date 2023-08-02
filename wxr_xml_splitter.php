<?php

global $argv;
$argv = $_SERVER['argv'];

// If script started from console (not included from other script) - lets run console interface
if (realpath($argv[0]) == __FILE__)
{
    $params = cli();

    try
    {
        split_wxr_file($params['src_filename'], $params['size_bytes'], $params['out_filename_format'], $params['debug'], true, $params['force_yes']);
    }
    catch (NotValidWXR $e)
    {
        echo "ERROR: " . $e->getMessage() . ". It seems that file is not a valid WXR file\n";
        exit(1);
    }
    catch (Exception $e)
    {
        echo "ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}


class NotValidWXR extends Exception
{
}

function split_wxr_file_old($src_filename, $size_bytes, $out_filename_format, $debug=false, $interactive=true, $force_yes=false)
{
    $dbg = function($message) use ($debug)
    {
        if ($debug) echo "DEBUG: $message\n";
    };
    
    if (strpos($out_filename_format, '%NUM%') === false)
    {
        throw new Exception("out_filename_format should have %NUM% substring in it, but it's not. It's: $out_filename_format");
    }
    
    $dbg("Loading file $src_filename");
    
    $content = file_get_contents($src_filename);
    
    $dbg("Loaded " . strlen($content) . " bytes. Checking is it truly XML");
    
    if (!is_xml($content, $error))
    {
        throw new Exception($error);
    }
    
    $dbg("The file is indeed an XML. Loading it into SimpleXMLElement");
    
    $xml = new SimpleXMLElement($content);
    
    $dbg("Checking for rss element in xml");
    
    if ($xml->getName() !== 'rss')
    {
        $dbg("root element is not rss");
        throw new NotValidWXR("Root element of XML is not RSS, but {$xml->getName()}");
    }
    
    $dbg("Checking for channel element in xml");
    
    if (!isset($xml->channel))
    {
        $dbg("channel element not found in xml");
        throw new NotValidWXR("No channel element found");
    }
    
    $dbg("Checking for WXR version");
    
    $namespaces = $xml->getNamespaces(true);
    $wp = $xml->channel->children($namespaces['wp']);
    
    if (isset($wp->wxr_version)) {
        $version = (string) $wp->wxr_version;
        $dbg("WXR Version: $version");
    }
    else
    {
        $dbg("<wp:wxr_version> tag not found");
        throw new NotValidWXR("No <wp:wxr_version> element found");
    }
    
    $dbg('Counting size of items');
    
    $sizes = [];
    
    $item_groups = [];
    $item_group = [];
    $item_group_size = 0;
    
    $i = 0;
    
    foreach ($xml->channel->item as $item) {
        $itemString = $item->asXML();
        $size = strlen($itemString);
        
        $item_group[] = $i;
        $item_group_size += $size;
        
        if ($item_group_size > $size_bytes)
        {
            $item_groups[] = $item_group;
            $item_group = [];
            $item_group_size = 0;
        }
        
        $sizes[] = $size;
        $i += 1;
    }
    
    if ($item_group_size > 0)
    {
        $item_groups[] = $item_group;
        $item_group = [];
        $item_group_size = 0;
    }
    
    $dbg('item_groups count:' . count($item_groups));
    
    $digits = strlen((string) count($item_groups));
    
    $real_format = str_replace('%NUM%', "_part_%0{$digits}d_of_%d", $out_filename_format);
    
    $dbg("printf format for filename: `$real_format`");
    
    
    if ($interactive)
    {
        
        $dbg("asking to proceed");
        
        echo "We are going to create " . count($item_groups) . " new files\n";
        if (count($item_groups) > 10)
        {
            echo "That's quite a lot, it could be unpractical to import such number of files into wordpress\n";
        }
        echo "Are you sure? (yes/no)\n";
        
        if ($force_yes)
        {
            echo "yes\n";
        }
        else
        {
            $input = trim(fgets(STDIN));
            if ($input !== 'yes') {
                throw new Exception("You've entered something other that 'yes'. Stopping");
            }
        }
    }
    else
    {
        $dbg('interactive mode disabled, skipping question');
    }
    
    $group_i = 0;
    
    foreach ($item_groups as $item_group)
    {
        $dbg("item_groups[$group_id]");
        
        $newXml = new SimpleXMLElement('<rss></rss>');
        // Copy attributes from the original rss element to the new one
        foreach ($xml->attributes() as $attrName => $attrValue) {
            $newXml->addAttribute($attrName, $attrValue);
        }
        // Copy namespaces
        $namespaces = $xml->getNamespaces(true);
        foreach ($namespaces as $prefix => $uri) {
            $newXml->addAttribute("xmlns:$prefix", $uri);
        }
        
        // Copy children of <rss>
        foreach ($xml->children() as $childName => $child) {
            if ($childName === 'channel') {
                $newChannel = $newXml->addChild('channel');
                
                $item_i = 0;
                
                // Copy children of <channel>
                foreach ($child->children() as $subChildName => $subChild) {
                    if ($subChildName !== 'item') {
                        // SimpleXMLElement does not provide a built-in way to copy nodes 
                        // from one document to another, so we use this trick
                        $dom = dom_import_simplexml($newChannel);
                        $domSubChild = dom_import_simplexml($subChild);
                        $domSubChild = $dom->ownerDocument->importNode($domSubChild, true);
                        $dom->appendChild($domSubChild);
                    }
                    else
                    {
                        if (in_array($item_i, $item_group))
                        {
                            $dbg("item[$item_i]");
                            $dom = dom_import_simplexml($newChannel);
                            $domSubChild = dom_import_simplexml($subChild);
                            $domSubChild = $dom->ownerDocument->importNode($domSubChild, true);
                            $dom->appendChild($domSubChild);
                        }
                        $item_i += 1;
                    }
                }
            } else {
                // Copy other children
                $dom = dom_import_simplexml($newXml);
                $domChild = dom_import_simplexml($child);
                $domChild = $dom->ownerDocument->importNode($domChild, true);
                $dom->appendChild($domChild);
            }
        }
        
        $new_filename = sprintf($real_format, $group_id + 1, count($item_groups));
        
        $dbg("saving newXml to $new_filename");
        
        echo "Saving $new_filename\n";
        
        $res = html_entity_decode($newXml->asXML());
        
        $res = preg_replace('#<\?xml\s*version\s*=\s*"1.0"\s*\?>#', '<?xml version="1.0" encoding="UTF-8"?>', $res);
        
        file_put_contents($new_filename, $res);
        
        $group_id += 1;
        
    }
    
}

function get_node_size($node) {
    $tempDoc = new DOMDocument();
    $clonedNode = $node->cloneNode(true);
    $importedNode = $tempDoc->importNode($clonedNode, true);
    $tempDoc->appendChild($importedNode);
    $xmlString = $tempDoc->saveXML();
    return strlen($xmlString); // This gives the length in bytes for UTF-8 encoded XML
}

function split_wxr_file($src_filename, $size_bytes, $out_filename_format, $debug=false, $interactive=true, $force_yes=false)
{
    $dbg = function($message) use ($debug)
    {
        if ($debug) echo "DEBUG: $message\n";
    };
    
    if (strpos($out_filename_format, '%NUM%') === false)
    {
        throw new Exception("out_filename_format should have %NUM% substring in it, but it's not. It's: $out_filename_format");
    }
    
    $dbg("Loading file $src_filename");
    
    $content = file_get_contents($src_filename);
    
    $dbg("Loaded " . strlen($content) . " bytes. Checking is it truly XML");
    
    if (!is_xml($content, $error))
    {
        throw new Exception($error);
    }
    
    $dbg("The file is indeed an XML. Loading it into SimpleXMLElement");
    
    $dom = new DOMDocument();
    $dom->loadXML($content);
    
    $dbg("Checking for rss element in xml");
    
    if ($dom->documentElement->tagName !== 'rss')
    {
        $dbg("root element is not rss");
        throw new NotValidWXR("Root element of XML is not RSS, but {$xml->getName()}");
    }
    
    $dbg("Checking for channel element in xml");
    
    $channel = $dom->getElementsByTagName('channel')->item(0);
    
    if (!$channel)
    {
        $dbg("channel element not found in xml");
        throw new NotValidWXR("No channel element found");
    }
    
    $dbg("Checking for WXR version");
    
    $wxrVersionElements = $channel->getElementsByTagName('wxr_version');
    
    
    if ($wxrVersionElements->length > 0 ) {
        $version = $wxrVersionElements->item(0)->nodeValue;;
        $dbg("WXR Version: $version");
    }
    else
    {
        $dbg("<wp:wxr_version> tag not found");
        throw new NotValidWXR("No <wp:wxr_version> element found");
    }
    
    $dbg('Counting size of items');
    
    $sizes = [];
    
    $item_groups = [];
    $item_group = [];
    $item_group_size = 0;
    
    $i = 0;
    
    foreach ($channel->childNodes as $node) {
        if ($node->nodeName === 'item') {
            $size = get_node_size($node);
            
            $item_group[] = $i;
            $item_group_size += $size;
            
            if ($item_group_size > $size_bytes)
            {
                $item_groups[] = $item_group;
                $item_group = [];
                $item_group_size = 0;
            }
            
            $sizes[] = $size;
            $i += 1;
        }
    }
    
    if ($item_group_size > 0)
    {
        $item_groups[] = $item_group;
        $item_group = [];
        $item_group_size = 0;
    }
    
    $dbg('item_groups count:' . count($item_groups));
    
    $digits = strlen((string) count($item_groups));
    
    $real_format = str_replace('%NUM%', "_part_%0{$digits}d_of_%d", $out_filename_format);
    
    $dbg("printf format for filename: `$real_format`");
    
    
    if ($interactive)
    {
        
        $dbg("asking to proceed");
        
        echo "We are going to create " . count($item_groups) . " new files\n";
        if (count($item_groups) > 10)
        {
            echo "That's quite a lot, it could be unpractical to import such number of files into wordpress\n";
        }
        echo "Are you sure? (yes/no)\n";
        
        if ($force_yes)
        {
            echo "yes\n";
        }
        else
        {
            $input = trim(fgets(STDIN));
            if ($input !== 'yes') {
                throw new Exception("You've entered something other that 'yes'. Stopping");
            }
        }
    }
    else
    {
        $dbg('interactive mode disabled, skipping yes/no question');
    }
    
    $group_i = 0;
    
    foreach ($item_groups as $item_group)
    {
        $dbg("item_groups[$group_i]");
        
        $newDom = clone $dom;
        
        $channel = $newDom->getElementsByTagName('channel')->item(0);

        $nodesToRemove = [];
            
        $item_num = 0;
        foreach ($channel->childNodes as $node) {
            if ($node->nodeName === 'item') {
                if (!in_array($item_num, $item_group))
                {
                    $item_num++;
                    $nodesToRemove[] = $node;
                }
                else
                {
                    $item_num++;
                }
            }            
        }
        
        foreach ($nodesToRemove as $node)
        {
            $channel->removeChild($node);
        }
        
        $new_filename = sprintf($real_format, $group_i + 1, count($item_groups));
        
        $dbg("saving newXml to $new_filename");
        
        echo "Saving $new_filename\n";
        
        $res = $newDom->saveXML();
        
        /* $res = preg_replace('#<\?xml\s*version\s*=\s*"1.0"\s*\?>#', '<?xml version="1.0" encoding="UTF-8"?>', $res);*/
        
        file_put_contents($new_filename, $res);
        
        $group_i += 1;
        
    }
    
}

function cli()
{
    $argv = $_SERVER['argv'];
    
    $debug = false;
    $force_yes = false;
    
    if (in_array('--debug', $argv))
    {
        $argv = array_values(array_diff($argv, ['--debug']));
        $debug = true;
    }
    
    if (in_array('--help', $argv))
    {
        usage();
        exit(0);
    }
    
    if (in_array('--yes', $argv))
    {
        $argv = array_diff($argv, ['--yes']);
        $force_yes = true;
    }
    
    $filename = $argv[1] ?? '';
    $size = $argv[2] ?? '';
    $out_filename = $argv[3] ?? $argv[1];

    if (!$filename && !$size)
    {
        usage();
        exit(1);
    }

    if ($filename && !file_exists($filename))
    {
        echo "Error: File `$filename` does not exists\n\n";
        exit(1);
    }

    if ($filename && !$size)
    {
        echo "Error: Output file size is not specified\n\n";
        exit(1);
    } 

    if (preg_match('#^(?P<number>[0-9]+)(?P<unit>[kKmM])$#', $size, $matches))
    {
        $size_number = (int)$matches['number'];
        $size_multipler = ['k' => 1024, 'K' => 1024, 'm' => 1024*1024, 'M' => 1024*1024][$matches['unit']];
        
        if (!$size_number)
        {
            echo "Error: output file size is zero\n\n";
            exit(1);
        }
        
        $size_bytes = $size_number * $size_multipler;
    }
    else
    {
        echo "Error: Output file size is not specified or has wrong format (value: $size)\n\n";
        exit(1);
    }
    
    if (!preg_match('#\.xml$#i', $out_filename))
    {
        $out_filename .= ".xml";
    }
    
    $out_filename_format = substr($out_filename, 0, strlen($out_filename) - 4) . "%NUM%.xml";

    return [
        "src_filename" => $filename,
        "size_bytes" => $size_bytes,
        "out_filename_format" => $out_filename_format,
        "debug" => $debug,
        "force_yes" => $force_yes
    ];
}

function usage()
{
    global $argv;
    echo "Usage: \n";
    echo "    php ${argv[0]} [--help] <filename> <size> [<output_file_name>] [--debug]\n";
    echo "\n";
    echo "Where:\n";
    echo "    --help             - display this help\n";
    echo "    --debug            - enabled debug output\n";
    echo "    --yes              - begin processing without asking of permission to start\n";
    echo "    <filename>         - source WXR filename, usually has xml extension\n";
    echo "    <size>             - size of resulting partial files, number with unit 'K' or 'M'\n";
    echo "                         for kilobytes or megabytes respectively, for example: 100K, 1M\n";
    echo "                         This size is applied to data items only, not including meta information,\n";
    echo "                         so, resulting files would be slightly bigger than this size.\n";
    echo "    <output_file_name> - filename, what will be used as a base one, to generate new files\n";
    echo "                         for example, if <output_file_name> is ./output.xml, \n";
    echo "                         resulting files would have names ./output_part_001_of_015.xml\n";
    echo "                         By default this filename is equal to source one.\n";
    
}

function is_xml($xml, &$error_message){
    libxml_use_internal_errors(true);

    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->loadXML($xml);

    $errors = libxml_get_errors();

    if(empty($errors)){
        return true;
    }

    $error = $errors[0];
    if($error->level < 3){
        return true;
    }

    $explodedxml = explode("r", $xml);
    $badxml = $explodedxml[($error->line)-1];

    $error_message = $error->message . ' at line ' . $error->line . '. Bad XML: ' . htmlentities($badxml);
    return false;
}
