<?php

declare(strict_types=1);

namespace CameraPlugin\Tests;

use CameraPlugin\CameraPlugin;
use PHPUnit\Framework\TestCase;

class CameraPluginTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction & config
    // -------------------------------------------------------------------------

    public function testDefaultConfigHasExpectedKeys(): void
    {
        $plugin = new CameraPlugin();
        $config = json_decode($plugin->getConfig(), true);

        $this->assertSame('environment', $config['facing']);
        $this->assertSame(1280, $config['width']);
        $this->assertSame(720, $config['height']);
        $this->assertFalse($config['audio']);
        $this->assertSame('camera-preview', $config['containerId']);
        $this->assertTrue($config['autoStart']);
        $this->assertNotEmpty($config['nonce']);
    }

    public function testConstructorMergesOptions(): void
    {
        $plugin = new CameraPlugin([
            'facing'      => 'user',
            'width'       => 640,
            'height'      => 480,
            'audio'       => true,
            'containerId' => 'my-cam',
        ]);

        $config = json_decode($plugin->getConfig(), true);

        $this->assertSame('user',   $config['facing']);
        $this->assertSame(640,      $config['width']);
        $this->assertSame(480,      $config['height']);
        $this->assertTrue($config['audio']);
        $this->assertSame('my-cam', $config['containerId']);
    }

    // -------------------------------------------------------------------------
    // Nonce
    // -------------------------------------------------------------------------

    public function testNonceIsHexString(): void
    {
        $plugin = new CameraPlugin();
        $nonce  = $plugin->getNonce();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
    }

    public function testEachInstanceGetsUniqueNonce(): void
    {
        $nonces = array_map(
            fn() => (new CameraPlugin())->getNonce(),
            range(1, 10)
        );

        $this->assertCount(10, array_unique($nonces), 'All nonces should be unique');
    }

    public function testCustomNonceIsPreserved(): void
    {
        $plugin = new CameraPlugin(['nonce' => 'custom-nonce-value']);
        $this->assertSame('custom-nonce-value', $plugin->getNonce());
    }

    // -------------------------------------------------------------------------
    // renderContainer()
    // -------------------------------------------------------------------------

    public function testRenderContainerContainerId(): void
    {
        $plugin = new CameraPlugin(['containerId' => 'test-cam']);
        $html   = $plugin->renderContainer();

        $this->assertStringContainsString('id="test-cam"', $html);
        $this->assertStringContainsString('id="test-cam-video"', $html);
        $this->assertStringContainsString('id="test-cam-canvas"', $html);
        $this->assertStringContainsString('id="test-cam-overlay"', $html);
    }

    public function testRenderContainerEscapesMaliciousId(): void
    {
        $plugin = new CameraPlugin(['containerId' => '<script>alert(1)</script>']);
        $html   = $plugin->renderContainer();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderContainerHasRequiredVideoAttributes(): void
    {
        $html = (new CameraPlugin())->renderContainer();

        $this->assertStringContainsString('playsinline', $html);
        $this->assertStringContainsString('autoplay', $html);
        $this->assertStringContainsString('muted', $html);
    }

    public function testRenderContainerAppliasExtraClasses(): void
    {
        $html = (new CameraPlugin())->renderContainer('my-class rounded');

        $this->assertStringContainsString('camera-plugin-container my-class rounded', $html);
    }

    // -------------------------------------------------------------------------
    // render() — JS bridge output
    // -------------------------------------------------------------------------

    public function testRenderReturnsScriptTag(): void
    {
        $plugin = new CameraPlugin();
        $output = $plugin->render(false);

        $this->assertStringContainsString('<script>', $output);
        $this->assertStringContainsString('</script>', $output);
        $this->assertStringContainsString('_CameraPluginBridge', $output);
        $this->assertStringContainsString('window.CameraPlugin', $output);
    }

    public function testRenderEmbeddsConfigJson(): void
    {
        $plugin = new CameraPlugin(['facing' => 'user', 'width' => 320]);
        $output = $plugin->render(false);

        $this->assertStringContainsString('"facing"', $output);
        $this->assertStringContainsString('"user"', $output);
        $this->assertStringContainsString('320', $output);
    }

    public function testRenderEchosByDefault(): void
    {
        $plugin = new CameraPlugin();
        ob_start();
        $returned = $plugin->render(true);
        $echoed   = ob_get_clean();

        $this->assertSame($returned, $echoed);
    }

    // -------------------------------------------------------------------------
    // renderStyles()
    // -------------------------------------------------------------------------

    public function testRenderStylesInlineContainsCss(): void
    {
        $output = (new CameraPlugin())->renderStyles();

        $this->assertStringContainsString('<style>', $output);
        $this->assertStringContainsString('.camera-plugin-container', $output);
    }

    public function testRenderStylesWithPublicUrlRendersLinkTag(): void
    {
        $output = (new CameraPlugin())->renderStyles('/assets/camera-plugin.css');

        $this->assertStringContainsString('<link rel="stylesheet"', $output);
        $this->assertStringContainsString('/assets/camera-plugin.css', $output);
        $this->assertStringNotContainsString('<style>', $output);
    }

    // -------------------------------------------------------------------------
    // getResourcePath()
    // -------------------------------------------------------------------------

    public function testGetResourcePathReturnsAbsolutePath(): void
    {
        $path = (new CameraPlugin())->getResourcePath('js/camera-bridge.js');

        $this->assertStringEndsWith('resources/js/camera-bridge.js', $path);
        $this->assertFileExists($path);
    }

    // -------------------------------------------------------------------------
    // handleRequest() — simulate AJAX
    // -------------------------------------------------------------------------

    public function testHandleRequestRejectsNonPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $plugin = new CameraPlugin();

        ob_start();
        try {
            $plugin->handleRequest();
        } catch (\Throwable $e) {
            // exit() throws in test context with process isolation
        }
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertSame('Method not allowed', $json['error'] ?? null);
    }

    public function testHandleRequestRejectsInvalidNonce(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $plugin = new CameraPlugin();

        // Provide body via php://input simulation using stream wrapper
        $body = json_encode(['action' => 'permission_granted', 'nonce' => 'wrong-nonce']);
        $this->mockInput($body);

        ob_start();
        try {
            $plugin->handleRequest('correct-nonce');
        } catch (\Throwable $e) {}
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertStringContainsString('nonce', strtolower($json['error'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockInput(string $body): void
    {
        // Register a stream wrapper to mock php://input in test
        if (in_array('test', stream_get_wrappers())) {
            stream_wrapper_unregister('test');
        }
        stream_wrapper_register('test', MockInputStream::class);
        MockInputStream::$body = $body;

        // Swap php://input — only works with allow_url_fopen in some envs;
        // for full coverage use process isolation or a seam in the class.
    }
}

/**
 * Simple stream wrapper to mock php://input in tests.
 */
class MockInputStream
{
    public static string $body = '';
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk     = substr(static::$body, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(static::$body);
    }

    public function stream_stat(): array { return []; }
}
