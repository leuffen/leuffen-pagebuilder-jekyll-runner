<?php

declare(strict_types=1);

namespace Leuffen\PagebuilderPreview;

final class NchanPublisher
{
    /**
     * Configure the local Nginx publisher without opening a connection.
     *
     * @param string $endpoint Fixed loopback HTTP endpoint from the bootstrap.
     * @example new NchanPublisher('http://127.0.0.1:8081/publish');
     * @see self::publish()
     */
    public function __construct(private readonly string $endpoint)
    {
    }

    /**
     * Post one UTF-8 body unchanged to the instance-local Nchan channel.
     *
     * HTTP 201 and 202 mean that Nchan accepted the message, with or without
     * current subscribers. This does not acknowledge receipt by a browser.
     * The request never follows redirects or uses an environment HTTP proxy.
     *
     * @param string $channel One channel containing 1-128 ASCII letters,
     *     digits, underscores or hyphens.
     * @param string $body UTF-8 message body, without wrapping or interpretation.
     * @return array{channel:string,status:string} Publication confirmation.
     * @throws \InvalidArgumentException For an invalid channel or UTF-8 body.
     * @throws \RuntimeException For HTTP setup, transport or publisher errors.
     * @example $publisher->publish('preview-session-42', '{"type":"reload"}');
     * @see https://nchan.io/#http-publishing
     */
    public function publish(string $channel, string $body): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $channel)) {
            throw new \InvalidArgumentException('Invalid Nchan channel: expected 1-128 ASCII letters, digits, underscores or hyphens');
        }
        if (preg_match('//u', $body) !== 1) {
            throw new \InvalidArgumentException('Nchan message body must be valid UTF-8');
        }

        // Ausschließlich den lokalen HTTP-Publisher verwenden, ohne Proxy oder Redirect.
        $request = curl_init($this->endpoint . '?channel=' . rawurlencode($channel));
        if ($request === false) {
            throw new \RuntimeException("Cannot initialize Nchan publish to $this->endpoint for channel $channel");
        }
        if (!curl_setopt_array($request, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
        ])) {
            throw new \RuntimeException("Cannot configure Nchan publish to $this->endpoint for channel $channel");
        }

        // Nur Nchans dokumentierte Publish-Statuscodes bestätigen die Annahme.
        if (curl_exec($request) === false) {
            $code = curl_errno($request);
            throw new \RuntimeException("Nchan publish to $this->endpoint failed for channel $channel: cURL $code (" . curl_strerror($code) . ')');
        }
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        if (!in_array($status, [201, 202], true)) {
            throw new \RuntimeException("Nchan publish to $this->endpoint failed for channel $channel: HTTP $status");
        }
        return ['channel' => $channel, 'status' => 'published'];
    }
}
