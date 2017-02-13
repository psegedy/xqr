<?php

#XQR:xseged00

// naciatanie parametrov programu
$options = getOptions($argv, $argc);
// Ak to bude symlink
if (is_link($options["input"])) {
    $options["input"] = readlink($options["input"]);
}
// ziskam absolutnu cestu
$options["input"] = realpath($options["input"]);

// -- input je zadany
// overenie ci je existuje, je citatatelny
if (isset($options["input"])) {
    if (is_readable($options["input"]) === TRUE && is_dir($options["input"]) === FALSE) {
        if (($handle = fopen($options["input"], "r")) === FALSE) {
            printErr("Failed to open input file", 2);
        }
        else {
            if (filesize($options["input"]) == 0) {
                $emptyFile = TRUE;
            }
            else {
                $inputFile = fread($handle, filesize($options["input"]));
            }
        }
        fclose($handle);
    }
    else
        printErr("Input file not exists|is not readable|is directory", 2);
}
// --input nezadane, beriem stdin
else 
    $inputFile = file_get_contents("php://stdin");

// -- output
// Ak to bude symlink
if (isset($options["output"])) {
    $outputFile = $options["output"];
    if (is_link($options["output"])) {
        $options["output"] = readlink($options["output"]);
    }
    $options["output"] = realpath($options["output"]);
    if (file_exists($options["output"])) {
        if (!(is_writable($options["output"]) === TRUE && is_dir($options["output"]) === FALSE)) {
            printErr("Bad output file", 3);
        }
    }
} else {
    $outputFile = "php://stdout";
}

// --query
if (isset($options["query"]))
    $query = $options["query"];
// --qf
if (isset($options["qf"])) {
    if (is_link($options["qf"])) {
        $options["qf"] = readlink($options["qf"]);
    }
    $options["qf"] = realpath($options["qf"]);

    if (is_readable($options["qf"]) === TRUE && is_dir($options["qf"]) === FALSE) {
        $query = file_get_contents($options["qf"]);
    }
    else
        printErr("qf is not readable", 80);
}

// --qf ani --query nezadane -> $query = ""
if (empty($options["query"]) && empty($options["qf"]))
    $query = "";
// PODLA WIS DISKUSIE
// prazdne query nedefinovane v gramatike -> chyba 80
if ($query === "") {
    printErr("Empty query", 80);
}

// nacitanie vstupneho xml
if (empty($emptyFile)) {
    if (($xmlIn = @simplexml_load_string($inputFile)) === FALSE) {
        printErr("Bad XML input", 4);
    }
}

// $query nie je prazdne -> skontrolujem spravnost dotazu a vyodnotim ho
if (!empty($query)) {
    $queryPart = parseQuery($query);
    $result = xmlSelect($queryPart, $xmlIn);
}

// -n   negenerovat hlavicku
if (isset($options["n"]))
    file_put_contents($outputFile, "");
else
    file_put_contents($outputFile, '<?xml version="1.0" encoding="utf-8"?>');
$xml = "";

// vyhodnoteni dotaz, vystup nie je prazdy
// z pola vytvorim string a ten zapisem do suboru
if (!empty($result)) {
    // --root zadane
    if (isset($options["root"]))
        $xml .= "<{$options["root"]}>";
    foreach ($result as $xmlPart) {
        $xml .= $xmlPart->asXML();
    }
    // --root zadane
    if (isset($options["root"]))
        $xml .= "</{$options["root"]}>";
    $xml .= "\n";
    file_put_contents($outputFile, $xml, FILE_APPEND);

} elseif (isset($options["root"])) {
    file_put_contents($outputFile, "<{$options["root"]}/>", FILE_APPEND);
}

// kontroluje spravnost dotazu
// rozdelenie dotazu na jednotlive casti
// pole $queryPart obsahuje casti dotazu 
function parseQuery($query)
{
    $query = preg_replace("/[[:space:]]+/", " ", $query);
    $pattern = "/^(SELECT\s+\w+)\s+(LIMIT\s+\d+\s+){0,1}(FROM.*)/";
    if (preg_match($pattern, $query, $matches)) {
        // SELECT element, queryPart["SELECT"] = element
        $querySplit = explode(" ", $matches[1]);
        $queryPart["SELECT"] = $querySplit[1];
        // LIMIT n
        if (!empty($matches[2])) {
            $querySplit = explode(" ", $matches[2]);
            $queryPart["LIMIT"] = (int)$querySplit[1];
        }
        // FROM
        $querySplit = explode(" ", $matches[3]);
        if (isset($querySplit[1])) {
            if (strcmp($querySplit[1], "WHERE") !== 0) {
                $queryPart["FROM"] = $querySplit[1];
                if (!preg_match("/^(\w+)?(\.\w+)?$/", $queryPart["FROM"])) {
                    printErr("Expecting <ELEMENT-OR-ATTRIBUTE> after FROM", 80);
                }
                $querySplit = array_slice($querySplit, 2);
            }
            else {
                // <FROM-ELM> je prazdny, nasleduje WHERE
                $queryPart["FROM"] = TRUE; 
                $querySplit = array_slice($querySplit, 1);
            }
        }
        else {
            // <FROM-ELM> je prazdny, koniec dotazu
            $queryPart["FROM"] = TRUE;
            return $queryPart;
        }
        
        //WHERE     
        if (isset($querySplit[0]) && ($querySplit[0] === "")) {
            unset($querySplit[0]);
        }
        if (isset($querySplit[0])) { 
            // za FROM element nieco nasleduje, ma to byt WHERE
            if (strcmp($querySplit[0], "WHERE") === 0) {
                $queryPart["WHERE"] = TRUE;
            }
            else {
                printErr("Wrong query '${querySplit[0]}' after '${queryPart["FROM"]}', expexting 'WHERE'", 80);
            }
            //$querySplit[0] je 'WHERE'
            //$querySplit[1] musi byt 'NOT' alebo <CONDITION>
            $step = 0;
            if (strcmp($querySplit[1], "NOT") === 0) {
                $queryPart["NOT"] = TRUE;
                $step = 1;
            }
            // <CONDITION> --> <ELEMENT-OR-ATTRIBUTE> <RELATION-OPERATOR> <LITERAL>
            $querySplit = array_slice($querySplit, 1+$step);
            $queryPart["CONDITION"] = implode(" ", $querySplit);
            if (!preg_match("/^(\w+)?(\.\w+)?\s*(<|>|=|CONTAINS)\s*(.*)/", $queryPart["CONDITION"], $matches))
                printErr("Wrong format of <CONDITION>", 80);

            $queryPart["COND_ELM"] = ($matches[1] !== "") ? $matches[1] : NULL ;    // element
            $queryPart["COND_ATTR"] = ($matches[2] !== "") ? $matches[2] : NULL ;  // attribute
            $queryPart["COND_OP"] = ($matches[3] !== "") ? $matches[3] : NULL ;    // operator
            $queryPart["COND_LIT"] = ($matches[4] !== "") ? $matches[4] : NULL ;   // literal

            $regex = preg_match('/\".*\"/', $queryPart["COND_LIT"]);
            if (strcmp($queryPart["COND_OP"], "CONTAINS") === 0) {
                if ($regex == 0 || $regex === FALSE) {
                    printErr("LITERAL must be a string", 80);
                }
            }
            else {
                if ($regex == 0 || $regex === FALSE) {
                    // literal nie je string
                    $regex2 = preg_match('/^\s*(-\d+|\d+)\s*$/', $queryPart["COND_LIT"]);
                    if ($regex2 == 0 || $regex2 === FALSE) {
                        printErr("Condition literal number must be integer", 80);
                    }
                    unset($regex2);
                }
            }
            unset($regex);
        }
    }
    else
        printErr("Wrong query", 80);
    return $queryPart;
}

// vyhodnotenie dotazu
function xmlSelect($queryPart, $xmlIn)
{
    $fromXpath = "";
    $selectedXml;

    // FROM <FROM-ELM> je prazdny, vystup je prazdny
    if ($queryPart["FROM"] === TRUE) {
        $selectedXml = "";
        return $selectedXml;
    }
    // <FROM-ELM> = ROOT, prehladava sa cele xml
    elseif ($queryPart["FROM"] === "ROOT") {
        $selectedXml = $xmlIn->xpath("/*");
    }
    // <FROM-ELM> nie je prazdne
    // $queryPart["FROM"] obsahuje hladany element|atribut
    // vyhlada sa len prvy vyskyt
    else {
        // potrebujem vytvorit xpath dotaz v tvare:
        // pre element.attribute (//element[@attribute][1])
        // pre element (//element[1])
        // pre .attribute (//*[@attribute][1])
        if (preg_match("/^\./", $queryPart["FROM"])) {
            // ak obsahuje . na zaciatku -> zadane len .attribute
            // nahradim .attribute za *[@attribute]
            $queryPart["FROM"] = preg_replace("/^\.(.*)/", "*[@$1]", $queryPart["FROM"]);
        }
        else
            // obsahuje elem.attr -> potrebujem elem[@attr]
            $queryPart["FROM"] = preg_replace("/\.(.*)/", "[@$1]", $queryPart["FROM"]);

        $selectedXml = $xmlIn->xpath("(//{$queryPart["FROM"]})[1]");
    }
    if (empty($selectedXml))
        return $selectedXml;
    
    // je zadane WHERE <CONDITION>
    if (isset($queryPart["WHERE"]) && $queryPart["WHERE"] === TRUE) {
        if (isset($queryPart["COND_ATTR"]) ) {
            if ($queryPart["COND_OP"] === "CONTAINS") {
                $queryPart["COND_ATTR"] = "contains({$queryPart["COND_ATTR"]}, {$queryPart["COND_LIT"]})";
            }
            else
                $queryPart["COND_ATTR"] .= $queryPart["COND_OP"] . $queryPart["COND_LIT"];

            $queryPart["COND_ATTR"] = preg_replace("/\.(.*)/", "@$1", $queryPart["COND_ATTR"]);

            if (isset($queryPart["NOT"])) {
                $queryPart["COND_ATTR"] = "[" . "not({$queryPart["COND_ATTR"]})" . "]";
            }
            else
                $queryPart["COND_ATTR"] = "[" . $queryPart["COND_ATTR"] . "]";

            // zadany atribut aj element
            if (isset($queryPart["COND_ELM"])) {
                $whereQuery = "//{$queryPart["COND_ELM"]}{$queryPart["COND_ATTR"]}";
            }
            // len atribut
            else {
                $whereQuery = "//{$queryPart["SELECT"]}{$queryPart["COND_ATTR"]}";
            }
        }
        // nie je zadany atribut
        else {
            //overim ci sa v hladanom element vo WHERE nenachadza dalsi element
            $tmpObjects = $selectedXml[0]->xpath(".//{$queryPart["COND_ELM"]}");
            foreach ($tmpObjects as $objectKey => $element) {
                foreach ($element as $key => $value) {
                    if ($value !== NULL) {
                        printErr("Bad input file", 4);
                    }
                }
            }
            //TODO: AKO TERAZ .// alebo ./ ?????????????
            if ($queryPart["COND_OP"] === "CONTAINS") {
                if (isset($queryPart["NOT"])) {
                    // Neguj <CONDITION>
                    $whereQuery = ".//{$queryPart["SELECT"]}[not(contains({$queryPart["COND_ELM"]}, {$queryPart["COND_LIT"]}))]";
                }
                else
                    $whereQuery = ".//{$queryPart["SELECT"]}[contains({$queryPart["COND_ELM"]}, {$queryPart["COND_LIT"]})]";
            }
            else {
                if (isset($queryPart["NOT"])) {
                    $whereQuery = ".//{$queryPart["SELECT"]}[not({$queryPart["COND_ELM"]}{$queryPart["COND_OP"]}{$queryPart["COND_LIT"]})]";
                }
                else
                    $whereQuery = ".//{$queryPart["SELECT"]}[{$queryPart["COND_ELM"]}{$queryPart["COND_OP"]}{$queryPart["COND_LIT"]}]";
            }
        }
        $selectedXml = $selectedXml[0];
        $selectedXml = $selectedXml->xpath($whereQuery);
    }
    else
        $selectedXml = $selectedXml[0]->xpath(".//{$queryPart["SELECT"]}");

    // LIMIT n
    if (isset($queryPart["LIMIT"]))
        $selectedXml = array_slice($selectedXml, 0, $queryPart["LIMIT"]);
    
    return $selectedXml;
}

// parsovanie argumentov
function getOptions($argv, $argc)
{
    // kontrola duplicitnych argumentov
    for ($i=0; $i < count($argv); $i++) { 
        $args[$i] = preg_replace("/=.*/", "", $argv[$i]);
    }
    $argsCount = array_count_values($args);
    foreach ($argsCount as $key => $value) {
        if ($value != 1) 
            printErr("Duplicit arguments", 1);
    }
    // getopt pri zadani napr. -input to vyhodnoti ze bolo zadane -n
    $i = 0;
    foreach ($argv as $value) {
        if (preg_match("/^-([^-]+)/", $argv[$i])) {
            if ($value != "-n") 
                printErr("Wrong argument $value", 1);
        }
        $i++;
    }

    $shortopts = "n";
    $longopts = array(
        "help",
        "input:",
        "output:",
        "query:",
        "qf:",
        "root:"
        );
    $options = getopt($shortopts, $longopts);

    if (array_key_exists("help", $options))
        if ($argc == 2) 
            printHelp();
        else
            printErr("Too many arguments with --help", 1);

    if (isset($options["query"]) && isset($options["qf"]))
        printErr("Cannot combine --query and --gf", 1);

    if (empty($options["query"]) && empty($options["qf"]))
        printErr("Query is empty", 80);

    if ((count($argv) -1) != count($options))
        printErr("Wrong arguments use --help", 1);

    return $options;
}

function printHelp()
{
    echo "USAGE:
    --help              prints help
    --input=filename    input file in XML format
    --output=filename   output file in XML format with content depending in query
    --query='query'     query in query language, defined by project task
    --qf=filename       query in query language, in external file
                        cannot combine with --query
    -n                  do not print out XML declaration
    --root=element      adds root element to output xml\n";
    exit(0);
}

function printErr($message, $errCode)
{
    file_put_contents("php://stderr", "$message\n");
    exit($errCode);
}
?>