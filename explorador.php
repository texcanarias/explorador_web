<?php
 
define('EOL', (PHP_SAPI == 'cli') ? PHP_EOL : '<br />');

$Raiz = '';//Url a recorrer

$Pila = getPilaInicial($Raiz); //En la pila se menten las URL que deben analizarse
$CacheRespuestas = array(); //Almacena la páguina y los valores devueltos para no explorarlo en el caso de que ya fuesen visitados
$Seo = array(); //Almacena datos básicos sobre SEO
explorarWeb($Pila, $Raiz);

/**
* Devuelve la pila de URL inicializada a la URL por defecto.
* @param $Raiz URL inicial
*/
function getPilaInicial($Raiz) {
    $Pila = array();
    $Pila[] = $Raiz;

    foreach ($Pila as $key => $value) {
        $Pila[$key] = strtolower(trim($value));
    }
    return $Pila;
}

/**
* Recorre la pila explorando cada una de las paginas
*/
function explorarWeb($Pila, $Raiz) {
    $Indice = 0;
    while ($Indice < count($Pila)) {
        explorarPagina($Pila,  $Raiz,  $Indice);
        ++$Indice;
    }
}

/**
 * Recoge la informacion del fichero mediante CURL
 * Se utiliza un sistema de cache para no tener que revisar el fichero más de 
 * una vez.
 */
function getInfoURL($URL, &$CacheRespuestas) {
    if (isset($CacheRespuestas[$URL])) {
        return $CacheRespuestas[$URL];
    } else {
        $c = curl_init($URL);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_VERBOSE, 0);
        curl_setopt($c, CURLOPT_HEADER, 1);

        $response = curl_exec($c);
        $header_size = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $content_type = curl_getinfo($c, CURLINFO_CONTENT_TYPE);
        $http_code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        $redirect_url = curl_getinfo($c, CURLINFO_REDIRECT_URL);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        curl_close($c);
        $Respuesta = array("URL_REDIRECT" => $redirect_url,
            "CONTENT_TYPE" => $content_type,
            "CODE" => $http_code,
            "HEADER" => $header,
            "BODY" => $body);
        $CacheRespuestas[$URL] = $Respuesta;
    }
    return $Respuesta;
}

/**
 * Se ha utilizado como base el algoritmo que viene en esta pagina
 * http://snipplr.com/view/12678/find-all-the-links-on-a-page/

 * @param type $Pila
 * @param type $Hash
 * @param type $Raiz
 * @param type $Indice
 */
function explorarPagina(&$Pila, $Raiz, $Indice = 0) {
    $Resultados = '';
    $Hash = array();
    $UrlAnalizada = trim($Pila[$Indice]);
    $Respuesta = getInfoURL($UrlAnalizada, $CacheRespuestas);
    $html = $Respuesta['BODY'];
    $codigo = $Respuesta['CODE'];
    $mime = filtrarMime($Respuesta['CONTENT_TYPE']);
    $Hash[$UrlAnalizada]['head']['code'] = $Respuesta['CODE'];
    $Hash[$UrlAnalizada]['head']['mime'] = $Respuesta['CONTENT_TYPE'];
    $Resultados .= $codigo . " | " . $UrlAnalizada . " | " . $mime . " " . EOL;
    if ($codigo >= 300 && $codigo <= 399) { //Si hay redireccionamiento muestra la URL
        if (!in_array($Respuesta['URL_REDIRECT'], $Pila)) {
            $Pila[] = $Respuesta['URL_REDIRECT'];
            $Hash[$UrlAnalizada]['head']['url_redirect'] = $Respuesta['URL_REDIRECT'];
        }
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    ++$Indice;

    //Análisis de HTML
    analisisA($xpath, $Raiz, $Hash, $UrlAnalizada, $Pila, $codigo);
    controladorAnalisisJS($xpath, $Raiz, $Hash, $UrlAnalizada);
    controladorAnalisisLink($xpath, $Raiz, $Hash, $UrlAnalizada, $Pila);
    analisisImg($xpath, $Raiz, $Hash, $UrlAnalizada);
    if('text/html' == $mime){
        analisisMeta($xpath, $Hash, $UrlAnalizada);
    }
    
    //Para cada pagina sacamos los resultados en un fichero.
    file_put_contents('resumen.txt', $Resultados, FILE_APPEND);
    file_put_contents('hash.txt', print_r($Hash, true), FILE_APPEND);
}

/**
 * Filtrar el CONTENT_TYPE para que solo refleje el mime
 * @param string $Mime
 */
function filtrarMime($Mime){
    $VMime = explode(";",$Mime);
    return trim($VMime[0]);
}

/**
* Busca en el documento los enlaces y extrae su informacion
*/
function analisisA($xpath, $Raiz, &$Hash, &$UrlAnalizada, &$Pila, $codigo) {
    $A_hrefs = $xpath->evaluate("/html/body//a");
    for ($i = 0, $t = $A_hrefs->length; $i < $t; $i++) {
        $href = $A_hrefs->item($i);
        $url = trim($href->getAttribute('href'));
        if (filtrarURL($url, $Raiz)) {
            $url = strtolower(anyadirRaiz($url, $Raiz, $UrlAnalizada));
            if (!in_array($url, $Pila)) {
                if (200 == $codigo || 302 == $codigo) {
                    $Pila[] = $url;
                }
            }
            setTablaHash($Hash, $UrlAnalizada, $url, 'a');
        }
    }
}

/**
* Hace dos llamadas a analisisJS una para el head y otra para el body
*/
function controladorAnalisisJS($xpath, $Raiz, &$Hash, &$UrlAnalizada) {
    for ($Head = 0; $Head <= 1; ++$Head) {
        analisisJS($xpath, $Raiz, $Hash, $UrlAnalizada, $Head);
    }
}

/**
* Análisis de las llamadas a los scripts (js) 
*/
function analisisJS($xpath, $Raiz, &$Hash, &$UrlAnalizada, $Head = 1) {
    //Busqueda de enlaces a ficheros JS en el head
    $Script_head_hrefs = $xpath->evaluate("/html/" . (($Head) ? "head" : "body") . "//script");
    for ($i = 0, $t = $Script_head_hrefs->length; $i < $t; $i++) {
        $href = $Script_head_hrefs->item($i);
        $url = trim($href->getAttribute('src'));
        if ("" != $url) {
            if (filtrarURL($url, $Raiz)) {
                $url = strtolower(anyadirRaiz($url, $Raiz, $UrlAnalizada));
                $Respuesta = getInfoURL($UrlAnalizada, $CacheRespuestas);
                $html = $Respuesta['BODY'];
                $codigo = $Respuesta['CODE'];
                $mime = $Respuesta['CONTENT_TYPE'];
                setTablaHash($Hash, $UrlAnalizada, $url,'js');
            }
        } else {
            $Hash[$UrlAnalizada]['Error'][] = "WARNING: Hay código JS intrusivo";
        }
    }
}

/**
* Hace dos llamadas a analisisLink una para el head y otra para el body
*/
function controladorAnalisisLink($xpath, $Raiz, &$Hash, &$UrlAnalizada, &$Pila) {
    for ($Head = 0; $Head <= 1; ++$Head) {
        analisisLink($xpath, $Raiz, $Hash, $UrlAnalizada, $Pila, $Head);
    }
}

/**
* Análisis de las llamadas a los links (css)
*/
function analisisLink($xpath, $Raiz, &$Hash, &$UrlAnalizada, &$Pila , $Head) {
    $Link_head_hrefs = $xpath->evaluate("/html/" . (($Head) ? "head" : "body") . "//link");
    for ($i = 0, $t = $Link_head_hrefs->length; $i < $t; $i++) {
        $href = $Link_head_hrefs->item($i);
        $url = trim($href->getAttribute('href'));
        $rel = trim($href->getAttribute('rel'));
        if ("" != $url) {
            if (filtrarURL($url, $Raiz)) {
                $url = strtolower(anyadirRaiz($url, $Raiz, $UrlAnalizada));
                $Respuesta = getInfoURL($UrlAnalizada, $CacheRespuestas);
                $html = $Respuesta['BODY'];
                $codigo = $Respuesta['CODE'];
                $mime = $Respuesta['CONTENT_TYPE'];
                setTablaHash($Hash, $UrlAnalizada, $url, "link");
                if (!in_array($url, $Pila)) {
                    if ((200 == $codigo || 302 == $codigo ) && "alternate" == $rel) {
                        $Pila[] = $url; //Los alternate se meten en la pila
                    }
                }
            }
        } else {
            $Hash[$UrlAnalizada]['Error'][] = "ERROR: Hay link sin href";
        }
    }
}

/**
* Busqueda de img en el body
*/
function analisisImg($xpath, $Raiz, &$Hash, &$UrlAnalizada) {
    $Img_body_hrefs = $xpath->evaluate("/html/body//img");
    for ($i = 0, $t = $Img_body_hrefs->length; $i < $t; $i++) {
        $href = $Img_body_hrefs->item($i);
        $url = trim($href->getAttribute('src'));
        $alt = trim($href->getAttribute('alt'));
        if ("" != $url) {
            if (filtrarURL($url, $Raiz)) {
                $url = strtolower(anyadirRaiz($url, $Raiz, $UrlAnalizada));
                $Respuesta = getInfoURL($url, $CacheRespuestas);
                $html = $Respuesta['BODY'];
                $codigo = $Respuesta['CODE'];
                $mime = $Respuesta['CONTENT_TYPE'];
                $Extra = ("" == $alt) ? "No tiene contenido en el alt" : "";
            }
        } else {
            $Hash[$UrlAnalizada]['Error'][] = "ERROR: Hay link sin href";
        }
    }
}

/**
* Análisis de la metainformacion 
*/
function analisisMeta($xpath, &$Hash, &$UrlAnalizada) {
    //Busqueda de img en el body
    $Img_body_hrefs = $xpath->evaluate("/html/head//title");
    for ($i = 0, $t = $Img_body_hrefs->length; $i < $t; $i++) {
        $title = $Img_body_hrefs->item($i);
        $Hash[$UrlAnalizada]['seo']['title'] = $title->ownerDocument->saveXML($title);
    }
    
    $Img_body_hrefs = $xpath->evaluate("/html/head//meta");
    for ($i = 0, $t = $Img_body_hrefs->length; $i < $t; $i++) {
        $href = $Img_body_hrefs->item($i);
        $name = trim($href->getAttribute('name'));
        $content = trim($href->getAttribute('content'));
        if("keywords" == $name || "description" == $name){
            $Hash[$UrlAnalizada]['seo'][$name] = $content;
        }
    }
}

/**
* Inserta una URL en la tabla Hash
*/
function setTablaHash(&$Hash, $UrlAnalizada, $url, $Categoria) {
    if (isset($Hash[$UrlAnalizada])) {
        if (!in_array($url, $Hash[$UrlAnalizada])) {
            $Hash[$UrlAnalizada][$Categoria][] = $url;
        }
    } else {
        $Hash[$UrlAnalizada][$Categoria][] = $url;
    }
}

/**
 * Determina si una URL debe ser procesada o no.
 * @param type $URL
 * @param type $Raiz
 * @return int 1 procesas 0 NO procesar
 */
function filtrarURL($URL, $Raiz) {
    if ($URL == '') {
        return 0;
    }

    if ($URL == '/#' OR $URL == '#') {
        return 0;
    }

    //Comprobamos que los enlaces con http pertencen a la Raiz
    $Cabecera = substr($URL, 0, 4);
    if ('http' == $Cabecera) {
        if (strcmp($Raiz, substr($URL, 0, strlen($Raiz)))) {
            return 0;
        }
    }

    return 1;
}

/**
* En el caso de que sea necesario introduce la raiz a la url.
*/
function anyadirRaiz($URL, $Raiz, $UrlAnalizada) {
    //Tratamos las URL que vienen de la forma "//directorio"
    $Cabecera = substr($URL, 0, 2);
    if ('//' == $Cabecera) {
        $URL = 'http:' . $URL;
    }else{
        $Cabecera = substr($URL, 0, 1);
        if ('/' == $Cabecera) {
            $URL = $Raiz . $URL;
        }else{
            $Cabecera = substr($URL, 0, 4);
            if ('http' != $Cabecera) {
                $URL = limpiarBarraSiExiste($URL, $UrlAnalizada);
            }
        }
    }
    return $URL;
}

function limpiarBarraSiExiste($URL, $UrlAnalizada) {
    $DirectorioAnterior = substr($URL, 0, 3);
    if ("../" == $DirectorioAnterior) {
        $URL = generarURLAPartirDeRelativa($URL, $UrlAnalizada);
    }
    else{
      $DirectorioAnterior = substr($URL, 0, 2);
      if ("./" == $DirectorioAnterior) {
          $URL = substr($URL, 2);
      }
      else{
        $Barra = substr($URL, 0, 1);
        if ("/" == $Barra) {
            $URL = substr($URL, 1);
        }
      }
    }
    return $URL;
}

function generarURLAPartirDeRelativa($URL, $UrlAnalizada) {
    //Descartamos que la ultima posicion de la UrlAnalizada sea / si lo es la quitamos
    $Barra = substr($UrlAnalizada, strlen($UrlAnalizada) - 1, 1);
    if ("/" == $Barra) {
        $UrlAnalizada = substr($UrlAnalizada, 0, strlen($UrlAnalizada) - 2);
    }

    //Buscamos la ultima posicion de / en la URL y quitamos todo lo que a está a continuación puesto que es el nombre la pagina
    $UltimaPosicionBarra = strrpos($UrlAnalizada, "/");
    $UrlAnalizada = substr($UrlAnalizada, 0, $UltimaPosicionBarra);

    //Fusionamos UrlAnalizada con URL y lo devolvemos
    return $UrlAnalizada . "/" . $URL;
}
