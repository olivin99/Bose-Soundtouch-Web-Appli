<?php

// ======================================================
// CONFIGURATION
// ======================================================

$speakers = [
    "BureauG" => "192.168.0.195",
    "BureauD" => "192.168.0.181",
    "Cuisine" => "192.168.0.225",
    "Chambre" => "192.168.0.224"
];

// Groupes stereophoniques : envoient la commande a plusieurs enceintes d'un coup
$groups = [
    "Bureau" => ["192.168.0.195", "192.168.0.181"],
];

$radios = [
    "FIP"                 => "http://icecast.radiofrance.fr/fip-midfi.mp3",
    "France Inter"        => "http://direct.franceinter.fr/live/franceinter-midfi.mp3",
    "France Info"         => "http://direct.franceinfo.fr/live/franceinfo-midfi.mp3",
    "Oui FM Classic Rock" => "http://ouifm3.ice.infomaniak.ch/ouifm3.mp3",
    "Jazz Radio"          => "http://jazz-wr04.ice.infomaniak.ch/jazz-wr04-128.mp3",
    "RTL"                 => "http://streaming.radio.rtl.fr/rtl-1-44-128",
    "Oui FM Rock 90s"     => "http://nineties.ice.infomaniak.ch/ouifmnineties.mp3",
    "RFM"                 => "http://rfm.lmn.fm/rfm.mp3",
    "ABC Lounge Radio"    => "http://192.168.0.80:8000/radio.mp3",
];

// ======================================================
// FONCTIONS HTTP
// ======================================================

function soapRequest($ip, $path, $soapAction, $xmlBody) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://$ip:8091$path");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/xml; charset="utf-8"',
        "SOAPACTION: \"$soapAction\""
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlBody);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);
    return ['result' => $result, 'error' => $error];
}

// Applique les options communes pour minimiser la latence sur reseau local
function _curlFast($ch, $url, $headers, $body) {
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);   // reseau local : 1s suffit
    curl_setopt($ch, CURLOPT_TCP_NODELAY,    true); // desactive l'algo de Nagle
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
}

// Boucle multi propre avec curl_multi_select (evite le busy-loop CPU)
function _multiRun($mh, array $handles) {
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running > 0) { curl_multi_select($mh, 0.01); }
    } while ($running > 0);
    $err = '';
    foreach ($handles as $ch) {
        $e = curl_error($ch);
        if ($e) { $err = $e; }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $err;
}

// Envoie la meme requete SOAP a plusieurs IPs en parallele
function soapMulti(array $ips, $path, $soapAction, $xmlBody) {
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($ips as $ip) {
        $ch = curl_init();
        _curlFast($ch, "http://$ip:8091$path", [
            'Content-Type: text/xml; charset="utf-8"',
            "SOAPACTION: \"$soapAction\""
        ], $xmlBody);
        curl_multi_add_handle($mh, $ch);
        $handles[$ip] = $ch;
    }
    return _multiRun($mh, $handles);
}

// Envoie la meme requete REST Bose a plusieurs IPs en parallele
function boseMulti(array $ips, $path, $xmlBody) {
    $mh      = curl_multi_init();
    $handles = [];
    foreach ($ips as $ip) {
        $ch = curl_init();
        _curlFast($ch, "http://$ip:8090$path",
            ['Content-Type: application/xml'],
            $xmlBody);
        curl_multi_add_handle($mh, $ch);
        $handles[$ip] = $ch;
    }
    return _multiRun($mh, $handles);
}

function bosePost($ip, $path, $xmlBody) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://$ip:8090$path");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlBody);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);
    return ['result' => $result, 'error' => $error];
}

// ======================================================
// PERSISTANCE (cookies 30 jours)
// ======================================================

$cookieTTL    = time() + 30 * 86400;
$savedSpeaker = $_COOKIE['bose_speaker'] ?? array_key_first($speakers);
$savedRadio   = $_COOKIE['bose_radio']   ?? array_key_first($radios);
$savedVolume  = intval($_COOKIE['bose_volume'] ?? 20);

if (!isset($speakers[$savedSpeaker]) && !isset($groups[$savedSpeaker])) { $savedSpeaker = "Bureau"; }
if (!isset($radios[$savedRadio]))     { $savedRadio   = array_key_first($radios); }

// ======================================================
// ENDPOINT AJAX VOLUME
// ======================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sleep') {
    header('Content-Type: application/json');
    $speaker = $_GET['speaker'] ?? '';
    $ips = isset($groups[$speaker]) ? $groups[$speaker]
         : (isset($speakers[$speaker]) ? [$speakers[$speaker]] : []);
    if (empty($ips)) {
        echo json_encode(['ok' => false, 'error' => 'Enceinte inconnue']);
        exit;
    }
    $err = boseMulti($ips, '/key', '<key state="press" sender="Gabbo">POWER</key>');
    echo json_encode(['ok' => !$err, 'error' => $err]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'play') {
    header('Content-Type: application/json');
    $speaker = $_GET['speaker'] ?? '';
    $radio   = $_GET['radio']   ?? '';
    $volume  = max(0, min(100, intval($_GET['volume'] ?? 20)));
    setcookie('bose_speaker', $speaker, time() + 30 * 86400, '/');
    setcookie('bose_radio',   $radio,   time() + 30 * 86400, '/');
    $ips = isset($groups[$speaker]) ? $groups[$speaker]
         : (isset($speakers[$speaker]) ? [$speakers[$speaker]] : []);
    if (empty($ips) || !isset($radios[$radio])) {
        echo json_encode(['ok' => false, 'error' => 'Parametre invalide']);
        exit;
    }
    $url = $radios[$radio];
    $xmlSetUri = '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"
            s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:SetAVTransportURI xmlns:u="urn:schemas-upnp-org:service:AVTransport:1">
      <InstanceID>0</InstanceID>
      <CurrentURI>' . htmlspecialchars($url) . '</CurrentURI>
      <CurrentURIMetaData></CurrentURIMetaData>
    </u:SetAVTransportURI>
  </s:Body>
</s:Envelope>';
    $xmlPlay = '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"
            s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:Play xmlns:u="urn:schemas-upnp-org:service:AVTransport:1">
      <InstanceID>0</InstanceID>
      <Speed>1</Speed>
    </u:Play>
  </s:Body>
</s:Envelope>';
    // 1) SetAVTransportURI sur toutes les IPs en parallele
    $err = soapMulti($ips, '/AVTransport/Control',
        'urn:schemas-upnp-org:service:AVTransport:1#SetAVTransportURI', $xmlSetUri);
    // 2) Play sur toutes les IPs en parallele (demarrage synchronise)
    $err2 = soapMulti($ips, '/AVTransport/Control',
        'urn:schemas-upnp-org:service:AVTransport:1#Play', $xmlPlay);
    if ($err2) { $err = $err2; }
    echo json_encode(['ok' => !$err, 'error' => $err]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'volume') {
    header('Content-Type: application/json');
    $speaker = $_GET['speaker'] ?? '';
    $volume  = max(0, min(100, intval($_GET['volume'] ?? 20)));
    setcookie('bose_speaker', $speaker, time() + 30 * 86400, '/');
    setcookie('bose_volume',  $volume,  time() + 30 * 86400, '/');
    // Groupe ou enceinte seule
    $ips = isset($groups[$speaker]) ? $groups[$speaker]
         : (isset($speakers[$speaker]) ? [$speakers[$speaker]] : []);
    if (empty($ips)) {
        echo json_encode(['ok' => false, 'error' => 'Enceinte inconnue']);
        exit;
    }
    $err = boseMulti($ips, '/volume', '<volume>' . $volume . '</volume>');
    echo json_encode(['ok' => !$err, 'error' => $err]);
    exit;
}

// ======================================================
// TRAITEMENT POST
// ======================================================

$message     = "";
$messageType = "info";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action  = $_POST['action']  ?? 'play';
    $speaker = $_POST['speaker'] ?? $savedSpeaker;
    $radio   = $_POST['radio']   ?? $savedRadio;
    $volume  = max(0, min(100, intval($_POST['volume'] ?? $savedVolume)));

    if (isset($speakers[$speaker]) || isset($groups[$speaker])) {
        setcookie('bose_speaker', $speaker, $cookieTTL, '/');
        $savedSpeaker = $speaker;
    }
    if (isset($radios[$radio])) {
        setcookie('bose_radio', $radio, $cookieTTL, '/');
        $savedRadio = $radio;
    }
    setcookie('bose_volume', $volume, $cookieTTL, '/');
    $savedVolume = $volume;

    // Resoudre les IPs : groupe ou enceinte seule
    $ips = isset($groups[$speaker]) ? $groups[$speaker]
         : (isset($speakers[$speaker]) ? [$speakers[$speaker]] : []);

    if (empty($ips)) {
        $message = "Enceinte inconnue.";
        $messageType = "error";
    } else {

        if ($action === 'play') {
            if (!isset($radios[$radio])) {
                $message = "Radio inconnue.";
                $messageType = "error";
            } else {
                $url = $radios[$radio];
                $xmlSetUri = '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"
            s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:SetAVTransportURI xmlns:u="urn:schemas-upnp-org:service:AVTransport:1">
      <InstanceID>0</InstanceID>
      <CurrentURI>' . htmlspecialchars($url) . '</CurrentURI>
      <CurrentURIMetaData></CurrentURIMetaData>
    </u:SetAVTransportURI>
  </s:Body>
</s:Envelope>';
                $xmlPlay = '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"
            s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:Play xmlns:u="urn:schemas-upnp-org:service:AVTransport:1">
      <InstanceID>0</InstanceID>
      <Speed>1</Speed>
    </u:Play>
  </s:Body>
</s:Envelope>';
                soapMulti($ips, '/AVTransport/Control',
                    'urn:schemas-upnp-org:service:AVTransport:1#SetAVTransportURI', $xmlSetUri);
                soapMulti($ips, '/AVTransport/Control',
                    'urn:schemas-upnp-org:service:AVTransport:1#Play', $xmlPlay);
                $label = isset($groups[$speaker]) ? $speaker . " (stereo)" : $speaker;
                $message = "Lecture lancee sur $label : $radio";
                $messageType = "success";
            }
        } elseif ($action === 'sleep') {
            $err = boseMulti($ips, '/key', '<key state="press" sender="Gabbo">POWER</key>');
            $label = isset($groups[$speaker]) ? $speaker . " (stereo)" : $speaker;
            $message = $err ? "Erreur : $err" : "$label mis en veille";
            $messageType = $err ? "error" : "success";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Radio Bose</title>
<style>

*, *::before, *::after { box-sizing: border-box; }

:root {
    --green:  #00b894;
    --green2: #00a383;
    --grey:   #636e72;
    --grey2:  #535c60;
    --bg:     #111111;
    --card:   #1c1c1e;
    --input:  #2c2c2e;
    --sep:    #2c2c2e;
}

html, body {
    margin: 0;
    padding: 0;
    background: var(--bg);
    color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    min-height: 100vh;
}

/* ---- Layout ---- */
.page {
    max-width: 480px;
    margin: 0 auto;
    padding: 20px 16px 40px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ---- Header ---- */
.header {
    text-align: center;
    padding: 10px 0 4px;
}
.header h1 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: .02em;
}
.header p {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--grey);
    text-transform: uppercase;
    letter-spacing: .08em;
}

/* ---- Section card ---- */
.section {
    background: var(--card);
    border-radius: 16px;
    overflow: hidden;
}

.section-title {
    font-size: 11px;
    color: var(--grey);
    text-transform: uppercase;
    letter-spacing: .1em;
    padding: 14px 16px 6px;
}

/* ---- Pill selector (enceintes) ---- */
.pill-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 0 16px 16px;
}

.pill {
    flex: 1 1 auto;
    min-width: 80px;
    padding: 12px 10px;
    border-radius: 12px;
    background: var(--input);
    border: 2px solid transparent;
    color: #ccc;
    font-size: 15px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.pill.active {
    background: #003d30;
    border-color: var(--green);
    color: var(--green);
}

/* ---- Radio list ---- */
.radio-list {
    display: flex;
    flex-direction: column;
}

.radio-item {
    display: flex;
    align-items: center;
    padding: 15px 16px;
    border-bottom: 1px solid var(--sep);
    cursor: pointer;
    transition: background .1s;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.radio-item:last-child { border-bottom: none; }
.radio-item:active { background: #2a2a2a; }

.radio-item .radio-dot {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid var(--grey);
    margin-right: 14px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color .15s;
}
.radio-item.active .radio-dot {
    border-color: var(--green);
}
.radio-item.active .radio-dot::after {
    content: '';
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--green);
}
.radio-item .radio-name {
    font-size: 17px;
    font-weight: 500;
}
.radio-item.active .radio-name {
    color: var(--green);
}

/* ---- Action buttons ---- */
.btn {
    padding: 18px 10px;
    border: none;
    border-radius: 14px;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: filter .1s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
}
.btn:active { filter: brightness(.85); }

.btn-play  { background: var(--green); color: #fff; }
.btn-sleep { background: var(--input); color: #ccc; font-size: 13px; padding: 10px; }

/* Grille enceintes : chaque enceinte = colonne avec son bouton veille */
.speaker-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    padding: 0 16px 16px;
}
.speaker-col {
    flex: 1 1 80px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.btn-launch {
    padding: 16px;
    width: calc(100% - 32px);
    margin: 0 16px 16px;
    border-radius: 14px;
}

/* ---- Volume ---- */
.volume-wrap {
    padding: 0 16px 20px;
}

.volume-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.volume-label {
    font-size: 13px;
    color: var(--grey);
    text-transform: uppercase;
    letter-spacing: .08em;
}
.volume-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--green);
    letter-spacing: -.02em;
}
#volume-status {
    font-size: 12px;
    min-width: 36px;
    text-align: right;
    color: var(--grey);
}

/* Gros boutons +/- */
.vol-btns {
    display: grid;
    grid-template-columns: 72px 1fr 72px;
    align-items: center;
    gap: 12px;
}

.vol-btn {
    height: 64px;
    border: none;
    border-radius: 14px;
    background: var(--input);
    color: #fff;
    font-size: 30px;
    font-weight: 300;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    -webkit-tap-highlight-color: transparent;
    transition: background .1s;
    user-select: none;
}
.vol-btn:active { background: #3a3a3c; }

/* Slider entre les boutons */
input[type="range"] {
    -webkit-appearance: none;
    width: 100%;
    height: 8px;
    border-radius: 4px;
    background: var(--input);
    outline: none;
    cursor: pointer;
}
input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--green);
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,184,148,.4);
}
input[type="range"]::-moz-range-thumb {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--green);
    border: none;
    cursor: pointer;
}

/* ---- Toast message ---- */
.toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    padding: 12px 24px;
    border-radius: 24px;
    font-size: 15px;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    transition: opacity .3s;
    pointer-events: none;
    z-index: 999;
}
.toast.show  { opacity: 1; }
.toast.info    { background: #1e3a4a; color: #74b9ff; }
.toast.success { background: #003d30; color: var(--green); }
.toast.error   { background: #3d1515; color: #ff6b6b; }

</style>
</head>
<body>

<div class="page">

    <!-- Header -->
    <div class="header">
        <h1>&#127925; Radio Bose</h1>
        <p>Contr&ocirc;le multi-pi&egrave;ces</p>
    </div>

    <!-- Enceintes -->
    <div class="section">
        <div class="section-title">Enceinte</div>
        <div class="speaker-grid" id="speaker-group">
            <?php foreach ($groups as $name => $ips): ?>
            <div class="speaker-col">
                <div class="pill pill-group-item <?= ($name === $savedSpeaker) ? 'active' : '' ?>"
                     data-speaker="<?= htmlspecialchars($name) ?>"
                     onclick="selectSpeaker(this)">
                    &#127911; <?= htmlspecialchars($name) ?>
                </div>
                <button class="btn btn-sleep" data-sp="<?= htmlspecialchars($name) ?>" onclick="sleepSpeaker(this.dataset.sp)">
                    &#128164; Veille
                </button>
            </div>
            <?php endforeach; ?>
            <?php
            $groupedIps = array_merge(...array_values($groups));
            foreach ($speakers as $name => $ip):
                if (in_array($ip, $groupedIps)) { continue; }
            ?>
            <div class="speaker-col">
                <div class="pill <?= ($name === $savedSpeaker) ? 'active' : '' ?>"
                     data-speaker="<?= htmlspecialchars($name) ?>"
                     onclick="selectSpeaker(this)">
                    <?= htmlspecialchars($name) ?>
                </div>
                <button class="btn btn-sleep" data-sp="<?= htmlspecialchars($name) ?>" onclick="sleepSpeaker(this.dataset.sp)">
                    &#128164; Veille
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-play btn-launch" onclick="submitAction('play')">&#9654; Lancer la radio</button>
    </div>

    <!-- Radios -->
    <div class="section">
        <div class="section-title">Radio</div>
        <div class="radio-list" id="radio-list">
            <?php foreach ($radios as $name => $url): ?>
            <div class="radio-item <?= ($name === $savedRadio) ? 'active' : '' ?>"
                 data-radio="<?= htmlspecialchars($name) ?>"
                 onclick="selectRadio(this)">
                <div class="radio-dot"></div>
                <div class="radio-name"><?= htmlspecialchars($name) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Volume -->
    <div class="section">
        <div class="section-title">Volume</div>
        <div class="volume-wrap">
            <div class="volume-row">
                <span class="volume-label">Niveau</span>
                <span class="volume-value" id="volume-display"><?= $savedVolume ?>%</span>
                <span id="volume-status"></span>
            </div>
            <div class="vol-btns">
                <button class="vol-btn" onclick="adjustVolume(-5)">&#8722;</button>
                <input type="range" id="volume-slider" min="0" max="100"
                       value="<?= $savedVolume ?>"
                       oninput="onVolumeInput(this.value)"
                       onchange="sendVolume(this.value)">
                <button class="vol-btn" onclick="adjustVolume(+5)">+</button>
            </div>
        </div>
    </div>

</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Formulaire caché -->
<form id="hidden-form" method="POST" style="display:none">
    <input type="hidden" name="action"  id="hf-action">
    <input type="hidden" name="speaker" id="hf-speaker">
    <input type="hidden" name="radio"   id="hf-radio">
    <input type="hidden" name="volume"  id="hf-volume">
</form>

<script>

// Sélections courantes
var currentSpeaker = <?= json_encode($savedSpeaker) ?>;
var currentRadio   = <?= json_encode($savedRadio) ?>;

function selectSpeaker(el) {
    document.querySelectorAll('.pill').forEach(function(p) { p.classList.remove('active'); });
    el.classList.add('active');
    currentSpeaker = el.dataset.speaker;
}

function selectRadio(el) {
    document.querySelectorAll('.radio-item').forEach(function(r) { r.classList.remove('active'); });
    el.classList.add('active');
    currentRadio = el.dataset.radio;
    playRadioAjax(currentSpeaker, currentRadio);
}

function playRadioAjax(speaker, radio) {
    var status = document.getElementById('volume-status');
    showToast('Lancement...', 'info');
    fetch('?ajax=play&speaker=' + encodeURIComponent(speaker) + '&radio=' + encodeURIComponent(radio))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showToast(data.ok ? 'Lecture lancee : ' + radio : 'Erreur : ' + data.error,
                      data.ok ? 'success' : 'error');
        })
        .catch(function() { showToast('Erreur reseau', 'error'); });
}

function submitAction(action) {
    if (action === 'play') {
        playRadioAjax(currentSpeaker, currentRadio);
        return;
    }
    document.getElementById('hf-action').value  = action;
    document.getElementById('hf-speaker').value = currentSpeaker;
    document.getElementById('hf-radio').value   = currentRadio;
    document.getElementById('hf-volume').value  = document.getElementById('volume-slider').value;
    document.getElementById('hidden-form').submit();
}

function sleepSpeaker(speaker) {
    showToast('Mise en veille...', 'info');
    fetch('?ajax=sleep&speaker=' + encodeURIComponent(speaker))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            showToast(data.ok ? speaker + ' mis en veille' : 'Erreur : ' + data.error,
                      data.ok ? 'success' : 'error');
        })
        .catch(function() { showToast('Erreur reseau', 'error'); });
}

// Volume
function onVolumeInput(val) {
    document.getElementById('volume-display').textContent = val + '%';
    document.getElementById('volume-status').textContent  = '';
}

var volTimer = null;
function sendVolume(val) {
    clearTimeout(volTimer);
    var status = document.getElementById('volume-status');
    status.textContent = '...';
    status.style.color = '#636e72';
    volTimer = setTimeout(function() {
        fetch('?ajax=volume&speaker=' + encodeURIComponent(currentSpeaker) + '&volume=' + val)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) {
                    status.textContent = 'OK';
                    status.style.color = '#00b894';
                } else {
                    status.textContent = 'Erreur';
                    status.style.color = '#ff6b6b';
                }
                setTimeout(function() { status.textContent = ''; }, 2000);
            })
            .catch(function() {
                status.textContent = 'Erreur';
                status.style.color = '#ff6b6b';
                setTimeout(function() { status.textContent = ''; }, 2000);
            });
    }, 300);
}

function adjustVolume(delta) {
    var slider = document.getElementById('volume-slider');
    slider.value = Math.max(0, Math.min(100, parseInt(slider.value) + delta));
    onVolumeInput(slider.value);
    sendVolume(slider.value);
}

function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + (type || 'info') + ' show';
    clearTimeout(t._timer);
    t._timer = setTimeout(function() { t.classList.remove('show'); }, 3000);
}

// Toast (message POST)
<?php if ($message): ?>
showToast(<?= json_encode($message) ?>, '<?= $messageType ?>');
<?php endif; ?>

</script>

</body>
</html>