<?php
// Mocking the message data from the provided JSON
$msg = json_decode('{"context":{"from":"447344651319","gs_id":"61f41a67-cb17-4abf-a5fd-4bd36103e388","id":"438ec569-d295-4ed6-8286-3cd26dd0713b","meta_msg_id":"wamid.HBgMMjAxMTI5Mjc0OTMwFQIAERgSRjlCREY4MzQ0MTQ0Q0U3NkE0AA=="},"from":"201129274930","id":"wamid.HBgMMjAxMTI5Mjc0OTMwFQIAEhggQUM5QjY5M0Q1RTlFMDJCMjQwMUEzMDI1RjFGOUZCRDYA","interactive":{"nfm_reply":{"body":"Sent","name":"flow","response_json":"{\"flow_token\":\"unused\"}"},"type":"nfm_reply"},"timestamp":"1774857828","type":"interactive"}', true);

$type = $msg['type'] ?? 'text';

// Replicating the updated logic in webhook.php
$text = match($type) {
    'text'     => $msg['text']['body']         ?? '',
    'image'    => $msg['image']['caption']     ?? '[Image]',
    'video'    => $msg['video']['caption']     ?? '[Video]',
    'document' => $msg['document']['caption']  ?? ($msg['document']['filename'] ?? '[Document]'),
    'audio'    => '[Voice Message]',
    'sticker'  => '[Sticker]',
    'location' => '[Location: ' . ($msg['location']['name'] ?? $msg['location']['address'] ?? ($msg['location']['latitude'] . ',' . $msg['location']['longitude'])) . ']',
    'contacts' => '[Contact: ' . ($msg['contacts'][0]['name']['formatted_name'] ?? 'Contact Card') . ']',
    'interactive' => match($msg['interactive']['type'] ?? '') {
        'button_reply' => $msg['interactive']['button_reply']['title']   ?? '[Button Reply]',
        'list_reply'   => $msg['interactive']['list_reply']['title']     ?? '[List Selection]',
        'nfm_reply'    => ($msg['interactive']['nfm_reply']['name'] ? $msg['interactive']['nfm_reply']['name'] . ': ' : '') . ($msg['interactive']['nfm_reply']['body'] ?? 'Sent'),
        default        => '[Interactive Message]'
    },
    'button'   => $msg['button']['text'] ?? '[Button Click]',
    default    => '[Unsupported message type: ' . $type . ']',
};

$extraData = [];
if ($type === 'location') {
    $extraData = [
        'latitude'  => $msg['location']['latitude']  ?? null,
        'longitude' => $msg['location']['longitude'] ?? null,
        'address'   => $msg['location']['address']   ?? null,
        'name'      => $msg['location']['name']      ?? null,
        'map_url'   => isset($msg['location']['latitude']) ? "https://www.google.com/maps?q={$msg['location']['latitude']},{$msg['location']['longitude']}" : null
    ];
} elseif ($type === 'interactive') {
    $extraData = [
        'interactive_type' => $msg['interactive']['type'] ?? null,
        'reply_id'         => $msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? null,
        'flow_name'        => $msg['interactive']['nfm_reply']['name'] ?? null,
        'flow_response'    => $msg['interactive']['nfm_reply']['response_json'] ?? null
    ];
}

echo "Detected Text: " . $text . "\n";
echo "Extra Data: " . json_encode($extraData, JSON_PRETTY_PRINT) . "\n";

if ($text === "flow: Sent" && $extraData['flow_name'] === "flow") {
    echo "SUCCESS: Logic works as expected.\n";
} else {
    echo "FAILURE: Logic did not produce expected results (Text: $text).\n";
    exit(1);
}
