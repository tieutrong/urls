<?php
  // CHANGE THIS!
  $username = "shortener";
  $password = "letmein";
  // USE ENTRY '*' for ALL
  $whitelist = array('192.168.200.*','127.0.0.1');
  $actionColor = "#f29800";
  $backgroundColor = "#efd6ac";
  $demoMode = false;
  $shortCodeLength = 4;

  header("X-Content-Type-Options: nosniff");
  header("X-Frame-Options: DENY");
  header("X-XSS-Protection: 1; mode=block");
  header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
  if(usingHTTPS()){
    header("Strict-Transport-Security: max-age=2592000");
  }

  ini_set( 'session.cookie_httponly', 1 );
  session_start();
  if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
  }
  $token = $_SESSION['token'];
  $db = loadDB();

  // ADDING URL
  if(isset($_POST["url"])){
    checkCSRF();
    checkLogin($whitelist);
    $res = addEntry($db,$_POST["url"]);
    if($res == false){
      outputSkeleton();
      outputError("This is not a valid URL",true);
      outputSkeletonEnd();
      die();
    }else{
      header("Location: ?admin&highlight=".$res);
      die();
    }
  // LOGOUT
  }elseif(isset($_POST["logout"])){
    checkLogin($whitelist);
    unset($_SESSION["loggedin"]);
    session_destroy();
    outputSkeleton();
    outputError("You have been logged out");
    outputSkeletonEnd();
    die();
  // LOGIN
  }elseif(isset($_POST["usr"])&&isset($_POST["pwd"])){
    checkCSRF();
    $usrok = hash_equals($_POST["usr"],$username);
    $pwdok = hash_equals($_POST["pwd"],$password);
    $loginok = $usrok && $pwdok;
    if($loginok){
      $_SESSION["loggedin"]=true;
      header("Location: ?admin");
      die();
    }else{
      outputSkeleton();
      outputError("Login failed","?admin");
      outputSkeletonEnd();
      die();
    }
  // DELETE URL
  }elseif(isset($_POST["delete"])){
    checkCSRF();
    checkLogin($whitelist);
    deleteEntry($db,$_POST["delete"]);
    header("Location: ?admin");
    die();
  // ADMIN "DASHBOARD"
  }elseif(isset($_GET["admin"])){
    checkLogin($whitelist);
    outputSkeleton();
    outputAdmin();
    foreach ($db as $key=>$entry) {
      outputEntry($key,$entry,isset($_GET["highlight"]) && $key===$_GET["highlight"]);
    }
    if(isset($_GET["highlight"])){
      ?>
      <script>
          window.scrollTo(0,document.body.scrollHeight);
      </script>
      <?php
    }
    outputSkeletonEnd();
    die();
  // RESOLVE SHORT CODE IF EXISTS
  }else{
    parse_str($_SERVER['QUERY_STRING'], $getparams);
    $code=key($getparams);
    if($code!=""){
      $url = resolveURL($db,$code);
      if($url !== false){
        if($demoMode){
          $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
          die("<h3>Demo Mode is active</h3><br>Otherwise I would redirect you to <b>".$url."</b>");
        }else{
          header("Location: ".$url);
          // From PHP 4.4.2 and 5.1.2 on header injections are not possible anymore
          header("Cache-Control: private, max-age=90");
          header("Referrer-Policy: origin");
          die();
        }
      }
    }
  }
  die();

  function checkCSRF(){
    if(!isset($_POST["csrf"]) || !hash_equals($_POST["csrf"],$_SESSION["token"])){
      outputSkeleton();
      outputError("CSRF failed");
      outputSkeletonEnd();
      die();
    }
  }

  function checkLogin($whitelist){
    if(!$_SESSION["loggedin"]){
      if(!isAllowed($_SERVER['REMOTE_ADDR'],$whitelist)){
        outputSkeleton();
        outputError("You are not allowed to access this page");
        outputSkeletonEnd();
        die();
      }
      outputSkeleton();
      outputLoginForm();
      outputSkeletonEnd();
      die();
    }
  }

  function resolveURL($db,$code){
    if(isset($db[$code])){
      return $db[$code];
    }
    return false;
  }

  function addEntry(&$db,$url){
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
      return false;
    }
    $code=getShortCode($url);
    $db[$code] = $url;
    saveDB($db);
    return $code;
  }

  function deleteEntry(&$db,$code){
    if(isset($db[$code])){
      unset($db[$code]);
      saveDB($db);
    }
  }

  function getShortCode($db){
    $i = 0;
    do{
      $code = generateRandomString();
      $i++;
      if($i>100){
        die("Cannot generate short code. Increase $shortCodeLength");
      }
    } while (array_key_exists($code,$db));
    return $code;
  }

  function generateRandomString() {
    global $shortCodeLength;
    $shortCodeLength = $shortCodeLength<2 ? 2 : $shortCodeLength;
    return substr(str_shuffle(str_repeat(
                 $x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
                 ceil($shortCodeLength/strlen($x)))),
                 1,
                 $shortCodeLength);
  }

  function saveDB($db){
    $dbfp = "db.json";
    $json_data = json_encode($db);
    $res = file_put_contents($dbfp, $json_data);
    if(!$res){
      die("Error saving DB");
    }
  }

  function loadDB(){
    $dbfp = "db.json";
    $dbf = fopen($dbfp, "r");
    if($dbf == null){
      createDB($dbfp);
      $dbf = fopen($dbfp, "r");
    }
    $json = json_decode(fread($dbf,filesize($dbfp)),true);
    if(json_last_error()!==JSON_ERROR_NONE){
      die("Error reading DB (".json_last_error().")");
    }
    fclose($dbf);
    return $json;
  }

  function createDB($dbfp){
    $dbf = fopen($dbfp, 'w') or die('Cannot create db');
    fwrite($dbf, "{}");
    fclose($db);
  }

  function isAllowed($ip,$whitelist){
    if(in_array($ip, $whitelist)){
      return true;
    }else{
      foreach($whitelist as $i){
        $wildcardPos = strpos($i, "*");
        if($wildcardPos !== false && substr($_SERVER['REMOTE_ADDR'], 0, $wildcardPos) . "*" == $i){
          return true;
        }
      }
    }
    return false;
  }

  function usingHTTPS(){
    return ( (! empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] == 'https') ||
    (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (! empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '443') );
  }

  function outputSkeleton(){
    global $backgroundColor;
    global $actionColor;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>URLS</title>
    </head>
    <body>
      <script>
        /*!
        * clipboard.js v2.0.0
        * https://zenorocha.github.io/clipboard.js
        *
        * Licensed MIT Â© Zeno Rocha
        */
        !function(t,e){"object"==typeof exports&&"object"==typeof module?module.exports=e():"function"==typeof define&&define.amd?define([],e):"object"==typeof exports?exports.ClipboardJS=e():t.ClipboardJS=e()}(this,function(){return function(t){function e(o){if(n[o])return n[o].exports;var r=n[o]={i:o,l:!1,exports:{}};return t[o].call(r.exports,r,r.exports,e),r.l=!0,r.exports}var n={};return e.m=t,e.c=n,e.i=function(t){return t},e.d=function(t,n,o){e.o(t,n)||Object.defineProperty(t,n,{configurable:!1,enumerable:!0,get:o})},e.n=function(t){var n=t&&t.__esModule?function(){return t.default}:function(){return t};return e.d(n,"a",n),n},e.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},e.p="",e(e.s=3)}([function(t,e,n){var o,r,i;!function(a,c){r=[t,n(7)],o=c,void 0!==(i="function"==typeof o?o.apply(e,r):o)&&(t.exports=i)}(0,function(t,e){"use strict";function n(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}var o=function(t){return t&&t.__esModule?t:{default:t}}(e),r="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(t){return typeof t}:function(t){return t&&"function"==typeof Symbol&&t.constructor===Symbol&&t!==Symbol.prototype?"symbol":typeof t},i=function(){function t(t,e){for(var n=0;n<e.length;n++){var o=e[n];o.enumerable=o.enumerable||!1,o.configurable=!0,"value"in o&&(o.writable=!0),Object.defineProperty(t,o.key,o)}}return function(e,n,o){return n&&t(e.prototype,n),o&&t(e,o),e}}(),a=function(){function t(e){n(this,t),this.resolveOptions(e),this.initSelection()}return i(t,[{key:"resolveOptions",value:function(){var t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:{};this.action=t.action,this.container=t.container,this.emitter=t.emitter,this.target=t.target,this.text=t.text,this.trigger=t.trigger,this.selectedText=""}},{key:"initSelection",value:function(){this.text?this.selectFake():this.target&&this.selectTarget()}},{key:"selectFake",value:function(){var t=this,e="rtl"==document.documentElement.getAttribute("dir");this.removeFake(),this.fakeHandlerCallback=function(){return t.removeFake()},this.fakeHandler=this.container.addEventListener("click",this.fakeHandlerCallback)||!0,this.fakeElem=document.createElement("textarea"),this.fakeElem.style.fontSize="12pt",this.fakeElem.style.border="0",this.fakeElem.style.padding="0",this.fakeElem.style.margin="0",this.fakeElem.style.position="absolute",this.fakeElem.style[e?"right":"left"]="-9999px";var n=window.pageYOffset||document.documentElement.scrollTop;this.fakeElem.style.top=n+"px",this.fakeElem.setAttribute("readonly",""),this.fakeElem.value=this.text,this.container.appendChild(this.fakeElem),this.selectedText=(0,o.default)(this.fakeElem),this.copyText()}},{key:"removeFake",value:function(){this.fakeHandler&&(this.container.removeEventListener("click",this.fakeHandlerCallback),this.fakeHandler=null,this.fakeHandlerCallback=null),this.fakeElem&&(this.container.removeChild(this.fakeElem),this.fakeElem=null)}},{key:"selectTarget",value:function(){this.selectedText=(0,o.default)(this.target),this.copyText()}},{key:"copyText",value:function(){var t=void 0;try{t=document.execCommand(this.action)}catch(e){t=!1}this.handleResult(t)}},{key:"handleResult",value:function(t){this.emitter.emit(t?"success":"error",{action:this.action,text:this.selectedText,trigger:this.trigger,clearSelection:this.clearSelection.bind(this)})}},{key:"clearSelection",value:function(){this.trigger&&this.trigger.focus(),window.getSelection().removeAllRanges()}},{key:"destroy",value:function(){this.removeFake()}},{key:"action",set:function(){var t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:"copy";if(this._action=t,"copy"!==this._action&&"cut"!==this._action)throw new Error('Invalid "action" value, use either "copy" or "cut"')},get:function(){return this._action}},{key:"target",set:function(t){if(void 0!==t){if(!t||"object"!==(void 0===t?"undefined":r(t))||1!==t.nodeType)throw new Error('Invalid "target" value, use a valid Element');if("copy"===this.action&&t.hasAttribute("disabled"))throw new Error('Invalid "target" attribute. Please use "readonly" instead of "disabled" attribute');if("cut"===this.action&&(t.hasAttribute("readonly")||t.hasAttribute("disabled")))throw new Error('Invalid "target" attribute. You can\'t cut text from elements with "readonly" or "disabled" attributes');this._target=t}},get:function(){return this._target}}]),t}();t.exports=a})},function(t,e,n){function o(t,e,n){if(!t&&!e&&!n)throw new Error("Missing required arguments");if(!c.string(e))throw new TypeError("Second argument must be a String");if(!c.fn(n))throw new TypeError("Third argument must be a Function");if(c.node(t))return r(t,e,n);if(c.nodeList(t))return i(t,e,n);if(c.string(t))return a(t,e,n);throw new TypeError("First argument must be a String, HTMLElement, HTMLCollection, or NodeList")}function r(t,e,n){return t.addEventListener(e,n),{destroy:function(){t.removeEventListener(e,n)}}}function i(t,e,n){return Array.prototype.forEach.call(t,function(t){t.addEventListener(e,n)}),{destroy:function(){Array.prototype.forEach.call(t,function(t){t.removeEventListener(e,n)})}}}function a(t,e,n){return u(document.body,t,e,n)}var c=n(6),u=n(5);t.exports=o},function(t,e){function n(){}n.prototype={on:function(t,e,n){var o=this.e||(this.e={});return(o[t]||(o[t]=[])).push({fn:e,ctx:n}),this},once:function(t,e,n){function o(){r.off(t,o),e.apply(n,arguments)}var r=this;return o._=e,this.on(t,o,n)},emit:function(t){var e=[].slice.call(arguments,1),n=((this.e||(this.e={}))[t]||[]).slice(),o=0,r=n.length;for(o;o<r;o++)n[o].fn.apply(n[o].ctx,e);return this},off:function(t,e){var n=this.e||(this.e={}),o=n[t],r=[];if(o&&e)for(var i=0,a=o.length;i<a;i++)o[i].fn!==e&&o[i].fn._!==e&&r.push(o[i]);return r.length?n[t]=r:delete n[t],this}},t.exports=n},function(t,e,n){var o,r,i;!function(a,c){r=[t,n(0),n(2),n(1)],o=c,void 0!==(i="function"==typeof o?o.apply(e,r):o)&&(t.exports=i)}(0,function(t,e,n,o){"use strict";function r(t){return t&&t.__esModule?t:{default:t}}function i(t,e){if(!(t instanceof e))throw new TypeError("Cannot call a class as a function")}function a(t,e){if(!t)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return!e||"object"!=typeof e&&"function"!=typeof e?t:e}function c(t,e){if("function"!=typeof e&&null!==e)throw new TypeError("Super expression must either be null or a function, not "+typeof e);t.prototype=Object.create(e&&e.prototype,{constructor:{value:t,enumerable:!1,writable:!0,configurable:!0}}),e&&(Object.setPrototypeOf?Object.setPrototypeOf(t,e):t.__proto__=e)}function u(t,e){var n="data-clipboard-"+t;if(e.hasAttribute(n))return e.getAttribute(n)}var l=r(e),s=r(n),f=r(o),d="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(t){return typeof t}:function(t){return t&&"function"==typeof Symbol&&t.constructor===Symbol&&t!==Symbol.prototype?"symbol":typeof t},h=function(){function t(t,e){for(var n=0;n<e.length;n++){var o=e[n];o.enumerable=o.enumerable||!1,o.configurable=!0,"value"in o&&(o.writable=!0),Object.defineProperty(t,o.key,o)}}return function(e,n,o){return n&&t(e.prototype,n),o&&t(e,o),e}}(),p=function(t){function e(t,n){i(this,e);var o=a(this,(e.__proto__||Object.getPrototypeOf(e)).call(this));return o.resolveOptions(n),o.listenClick(t),o}return c(e,t),h(e,[{key:"resolveOptions",value:function(){var t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:{};this.action="function"==typeof t.action?t.action:this.defaultAction,this.target="function"==typeof t.target?t.target:this.defaultTarget,this.text="function"==typeof t.text?t.text:this.defaultText,this.container="object"===d(t.container)?t.container:document.body}},{key:"listenClick",value:function(t){var e=this;this.listener=(0,f.default)(t,"click",function(t){return e.onClick(t)})}},{key:"onClick",value:function(t){var e=t.delegateTarget||t.currentTarget;this.clipboardAction&&(this.clipboardAction=null),this.clipboardAction=new l.default({action:this.action(e),target:this.target(e),text:this.text(e),container:this.container,trigger:e,emitter:this})}},{key:"defaultAction",value:function(t){return u("action",t)}},{key:"defaultTarget",value:function(t){var e=u("target",t);if(e)return document.querySelector(e)}},{key:"defaultText",value:function(t){return u("text",t)}},{key:"destroy",value:function(){this.listener.destroy(),this.clipboardAction&&(this.clipboardAction.destroy(),this.clipboardAction=null)}}],[{key:"isSupported",value:function(){var t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:["copy","cut"],e="string"==typeof t?[t]:t,n=!!document.queryCommandSupported;return e.forEach(function(t){n=n&&!!document.queryCommandSupported(t)}),n}}]),e}(s.default);t.exports=p})},function(t,e){function n(t,e){for(;t&&t.nodeType!==o;){if("function"==typeof t.matches&&t.matches(e))return t;t=t.parentNode}}var o=9;if("undefined"!=typeof Element&&!Element.prototype.matches){var r=Element.prototype;r.matches=r.matchesSelector||r.mozMatchesSelector||r.msMatchesSelector||r.oMatchesSelector||r.webkitMatchesSelector}t.exports=n},function(t,e,n){function o(t,e,n,o,r){var a=i.apply(this,arguments);return t.addEventListener(n,a,r),{destroy:function(){t.removeEventListener(n,a,r)}}}function r(t,e,n,r,i){return"function"==typeof t.addEventListener?o.apply(null,arguments):"function"==typeof n?o.bind(null,document).apply(null,arguments):("string"==typeof t&&(t=document.querySelectorAll(t)),Array.prototype.map.call(t,function(t){return o(t,e,n,r,i)}))}function i(t,e,n,o){return function(n){n.delegateTarget=a(n.target,e),n.delegateTarget&&o.call(t,n)}}var a=n(4);t.exports=r},function(t,e){e.node=function(t){return void 0!==t&&t instanceof HTMLElement&&1===t.nodeType},e.nodeList=function(t){var n=Object.prototype.toString.call(t);return void 0!==t&&("[object NodeList]"===n||"[object HTMLCollection]"===n)&&"length"in t&&(0===t.length||e.node(t[0]))},e.string=function(t){return"string"==typeof t||t instanceof String},e.fn=function(t){return"[object Function]"===Object.prototype.toString.call(t)}},function(t,e){function n(t){var e;if("SELECT"===t.nodeName)t.focus(),e=t.value;else if("INPUT"===t.nodeName||"TEXTAREA"===t.nodeName){var n=t.hasAttribute("readonly");n||t.setAttribute("readonly",""),t.select(),t.setSelectionRange(0,t.value.length),n||t.removeAttribute("readonly"),e=t.value}else{t.hasAttribute("contenteditable")&&t.focus();var o=window.getSelection(),r=document.createRange();r.selectNodeContents(t),o.removeAllRanges(),o.addRange(r),e=o.toString()}return e}t.exports=n}])});

        document.addEventListener("DOMContentLoaded", function(event) {
          var clipboard = new ClipboardJS('.clipboard');
        });
      </script>
      <style>
        html *{
          font-family: Helvetica
        }
        hr{
          color: <?= $backgroundColor ?>;
          margin-top: 20px;
        }
        .hand {
          cursor:pointer;
        }
        input, button{
          width:100%;
          padding: 5px;
          display: block;
          padding: .375rem .75rem;
          font-size: 1rem;
          line-height: 1.5;
          color: #495057;
          background-color: #fff;
          background-image: none;
          background-clip: padding-box;
          border: 1px solid #abb0b5;
          border-radius: .25rem;
          transition: border-color ease-in-out .15s,box-shadow ease-in-out .15s;
          box-sizing: border-box;
        }
	.action{
          background-color :<?= $actionColor ?> !important;
        }
        .content{
          border-radius: 10px;
          margin-top: 20px;
          padding-top: 20px;
          padding-bottom: 20px;
          margin-left: 20vw;
          margin-right: 20vw;
          border-color: <?= $backgroundColor ?>;
          border-frame: 2px;
          border-style: solid;
        }
        .innercontent{
          margin-left: 10vw;
          margin-right: 10vw;
        }
      </style>
      <div class="content">
        <div class="innercontent">
    <?php
  }

  function outputSkeletonEnd(){
    ?>
        </div>
      </div>
    </body>
    </html>
    <?php
  }

  function outputLoginForm(){
    global $token;
    ?>
    <form method="POST">
      <input type="text" name="usr" placeholder="Username">
      <br><br>
      <input type="password" name="pwd" placeholder="Password">
      <br><br>
      <input type="hidden" name="csrf" value="<?= $_SESSION["token"] ?>">
      <input type="submit" value="Login">
    </form>
    <?php
  }

  function outputAdmin(){
    global $token;
    ?>
      <br>
      <table>
        <tr><td style="width:100%">
        <form method="POST">
          <input type="text" name="url" placeholder="https://www.long.url.com/"><br>
          <input type="hidden" name="csrf" value="<?= $token ?>">
          <input type="submit" class="hand action" value="Shorten URL">
        </form>
        </td><td>
          <div style="width:20px"></div>
        </td><td>
        <form method="POST">
          <input type="hidden" name="logout" value="true">
          <input type="hidden" name="csrf" value="<?= $token ?>">
          <input type="submit" class="hand" value="Logout">
        </form>
        <br>
        <form method="GET" action="https://github.com/ozzi-/urls">
          <input type="submit" class="hand" value="Project">
        </form>
        </td></tr>
      </table>
      <hr noshade><br>
    <?php
  }

  function outputEntry($key,$entry,$highlight){
    global $token;
    global $backgroundColor;
    $currentURL = getCurrentURL();
    $shortURL = getCurrentURL()."?".$key;
    $entry = htmlspecialchars($entry, ENT_QUOTES, 'UTF-8');
    ?>
      <form method="GET" action="<?= $currentURL ?>">
        <input type="hidden" name="<?= $key ?>">
        <input type="submit" class="hand action" style="text-align: left; <?php if($highlight){ echo('background-color: '.$backgroundColor.' !important;'); }?>" value="<?= $key ?> | <?= $entry ?>">
      </form>
      <table>
        <tr><td>
        <button class="hand clipboard" data-clipboard-text="<?= $shortURL ?>">
          Copy Short URL
        </button>
        </td><td>
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this shortened URL?\n\rThis might break existing links.');">
          <input type="hidden" name="delete" value="<?= $key ?>">
          <input type="hidden" name="csrf" value="<?= $token ?>">
          <input type="submit" class="hand" value="&#128465;">
        </form>
        </td></tr>
     </table>
     <br><br>
    <?php
  }

  function getCurrentURL(){
    $protocol = usingHTTPS()?"https://":"http://";
    return $protocol.$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF']."KEK");
  }

  function outputError($errorstrng,$goTo=false){
    ?>
      <div style="text-align: center" class="hand">
        <button class="hand" style="height:150%;"
	<?php if($goTo===false){ ?>
          onclick="window.history.go(-1)"
        <?php } else { ?>
          onclick="window.location.replace('?admin');"
        <?php
        }
        ?>><?= $errorstrng ?></button>
      </div>
    <?php
  }
?>
