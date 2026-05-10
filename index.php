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

// API UPnP/SOAP -- port 8091 (lancement radio)
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

// API REST Bose SoundTouch -- port 8090 (volume, touches)
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

// Valider les valeurs cookiees (au cas ou la config change)
if (!isset($speakers[$savedSpeaker])) { $savedSpeaker = array_key_first($speakers); }
if (!isset($radios[$savedRadio]))     { $savedRadio   = array_key_first($radios); }

// ======================================================
// TRAITEMENT DES ACTIONS POST
// ======================================================

$message     = "";
$messageType = "info";

// ======================================================
// ENDPOINT AJAX VOLUME (appelé par fetch, retourne JSON)
// ======================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'volume') {
    header('Content-Type: application/json');
    $speaker = $_GET['speaker'] ?? '';
    $volume  = max(0, min(100, intval($_GET['volume'] ?? 20)));
    if (!isset($speakers[$speaker])) {
        echo json_encode(['ok' => false, 'error' => 'Enceinte inconnue']);
        exit;
    }
    setcookie('bose_speaker', $speaker, time() + 30 * 86400, '/');
    setcookie('bose_volume',  $volume,  time() + 30 * 86400, '/');
    $r = bosePost($speakers[$speaker], '/volume', '<volume>' . $volume . '</volume>');
    echo json_encode(['ok' => !$r['error'], 'error' => $r['error']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action  = $_POST['action']  ?? 'play';
    $speaker = $_POST['speaker'] ?? $savedSpeaker;
    $radio   = $_POST['radio']   ?? $savedRadio;
    $volume  = max(0, min(100, intval($_POST['volume'] ?? $savedVolume)));

    // Persister toutes les selections a chaque POST
    if (isset($speakers[$speaker])) {
        setcookie('bose_speaker', $speaker, $cookieTTL, '/');
        $savedSpeaker = $speaker;
    }
    if (isset($radios[$radio])) {
        setcookie('bose_radio', $radio, $cookieTTL, '/');
        $savedRadio = $radio;
    }
    setcookie('bose_volume', $volume, $cookieTTL, '/');
    $savedVolume = $volume;

    if (!isset($speakers[$speaker])) {
        $message     = "Enceinte inconnue.";
        $messageType = "error";
    } else {

        $ip = $speakers[$speaker];

        // --------------------------------------------------
        // ACTION : LANCER LA RADIO (UPnP SOAP port 8091)
        // --------------------------------------------------
        if ($action === 'play') {

            if (!isset($radios[$radio])) {
                $message     = "Radio inconnue.";
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

                soapRequest($ip, '/AVTransport/Control',
                    'urn:schemas-upnp-org:service:AVTransport:1#SetAVTransportURI',
                    $xmlSetUri);

                soapRequest($ip, '/AVTransport/Control',
                    'urn:schemas-upnp-org:service:AVTransport:1#Play',
                    $xmlPlay);

                $message     = "Lecture lancee sur $speaker : $radio";
                $messageType = "success";
            }
        }

        // --------------------------------------------------
        // ACTION : VOLUME (REST port 8090)
        // --------------------------------------------------
        elseif ($action === 'volume') {

            $r = bosePost($ip, '/volume', '<volume>' . $volume . '</volume>');

            if ($r['error']) {
                $message     = "Erreur volume sur $speaker : " . $r['error'];
                $messageType = "error";
            } else {
                $message     = "Volume regle a $volume% sur $speaker";
                $messageType = "success";
            }
        }

        // --------------------------------------------------
        // ACTION : MISE EN VEILLE (REST port 8090)
        // --------------------------------------------------
        elseif ($action === 'sleep') {

            $r = bosePost($ip, '/key', '<key state="press" sender="Gabbo">POWER</key>');

            if ($r['error']) {
                $message     = "Erreur mise en veille sur $speaker : " . $r['error'];
                $messageType = "error";
            } else {
                $message     = "$speaker mis en veille";
                $messageType = "success";
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Radio Bose</title>
<style>

*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: Arial, sans-serif;
    background: #111;
    color: white;
    margin: 0;
    padding: 20px;
}

.container {
    max-width: 500px;
    margin: auto;
}

.card {
    background: #1e1e1e;
    border-radius: 15px;
    padding: 25px;
}

h1 {
    text-align: center;
    margin: 0 0 20px;
}

label {
    display: block;
    font-size: 13px;
    color: #aaa;
    margin-top: 15px;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: .05em;
}

select,
button {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: 10px;
    font-size: 17px;
}

select {
    background: #2a2a2a;
    color: white;
    cursor: pointer;
}

.btn-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 15px;
}

.btn-play {
    background: #00b894;
    color: white;
    font-weight: bold;
    cursor: pointer;
}
.btn-play:hover  { background: #00a383; }

.btn-sleep {
    background: #636e72;
    color: white;
    font-weight: bold;
    cursor: pointer;
}
.btn-sleep:hover { background: #535c60; }

.volume-section {
    margin-top: 20px;
    background: #2a2a2a;
    border-radius: 12px;
    padding: 15px;
}

.volume-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.volume-header span {
    font-size: 13px;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: .05em;
}

#volume-display {
    font-size: 16px;
    font-weight: bold;
    color: #00b894;
    min-width: 42px;
    text-align: right;
}

.volume-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

input[type="range"] {
    -webkit-appearance: none;
    flex: 1;
    height: 6px;
    border-radius: 3px;
    background: #444;
    outline: none;
    cursor: pointer;
}
input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #00b894;
    cursor: pointer;
}
input[type="range"]::-moz-range-thumb {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #00b894;
    border: none;
    cursor: pointer;
}

.vol-icon {
    font-size: 20px;
    cursor: pointer;
    user-select: none;
    flex-shrink: 0;
}



.message {
    margin-top: 20px;
    padding: 14px;
    border-radius: 10px;
    text-align: center;
    font-size: 15px;
}
.message.success { background: #00403a; color: #00cdb5; }
.message.error   { background: #3d1515; color: #ff6b6b; }
.message.info    { background: #2d3436; color: #dfe6e9; }

.sep {
    border: none;
    border-top: 1px solid #333;
    margin: 20px 0 0;
}

</style>
</head>
<body>

<div class="container">
<div class="card">

    <h1>&#127925; Radio Bose</h1>

    <label for="sel-speaker">Enceinte</label>
    <select id="sel-speaker">
        <?php foreach ($speakers as $name => $ip): ?>
            <option value="<?= htmlspecialchars($name) ?>"
                <?= ($name === $savedSpeaker) ? 'selected' : '' ?>>
                <?= htmlspecialchars($name) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <hr class="sep">

    <label for="sel-radio">Radio</label>
    <select id="sel-radio">
        <?php foreach ($radios as $name => $url): ?>
            <option value="<?= htmlspecialchars($name) ?>"
                <?= ($name === $savedRadio) ? 'selected' : '' ?>>
                <?= htmlspecialchars($name) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div class="btn-row">
        <button class="btn-play"  onclick="submitAction('play')">&#9654; Lancer la radio</button>
        <button class="btn-sleep" onclick="submitAction('sleep')">&#128164; Mettre en veille</button>
    </div>

    <hr class="sep">

    <div class="volume-section">
        <div class="volume-header">
            <span>Volume</span>
            <span id="volume-display"><?= $savedVolume ?>%</span>
            <span id="volume-status" style="font-size:12px;color:#636e72;min-width:60px;text-align:right"></span>
        </div>
        <div class="volume-controls">
            <span class="vol-icon" onclick="adjustVolume(-5)">&#128264;</span>
            <input type="range" id="volume-slider" min="0" max="100"
                   value="<?= $savedVolume ?>"
                   oninput="onVolumeInput(this.value)"
                   onchange="sendVolume(this.value)">
            <span class="vol-icon" onclick="adjustVolume(+5)">&#128266;</span>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="message <?= htmlspecialchars($messageType) ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

</div>
</div>

<form id="hidden-form" method="POST" style="display:none">
    <input type="hidden" name="action"  id="hf-action">
    <input type="hidden" name="speaker" id="hf-speaker">
    <input type="hidden" name="radio"   id="hf-radio">
    <input type="hidden" name="volume"  id="hf-volume">
</form>

<script>
function submitAction(action) {
    document.getElementById('hf-action').value  = action;
    document.getElementById('hf-speaker').value = document.getElementById('sel-speaker').value;
    document.getElementById('hf-radio').value   = document.getElementById('sel-radio').value;
    document.getElementById('hf-volume').value  = document.getElementById('volume-slider').value;
    document.getElementById('hidden-form').submit();
}

// Mise a jour affichage en temps reel pendant le glissement
function onVolumeInput(val) {
    document.getElementById('volume-display').textContent = val + '%';
    document.getElementById('volume-status').textContent = '';
}

// Envoi AJAX au relachement du slider
var volTimer = null;
function sendVolume(val) {
    clearTimeout(volTimer);
    var status = document.getElementById('volume-status');
    status.textContent = '...';
    status.style.color = '#636e72';
    volTimer = setTimeout(function() {
        var speaker = document.getElementById('sel-speaker').value;
        fetch('?ajax=volume&speaker=' + encodeURIComponent(speaker) + '&volume=' + val)
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
</script>

</body>
</html>