<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DockerImageParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DockerImageParserTest extends TestCase
{
    #[Test]
    public function parses_simple_image_with_latest_tag()
    {
        $parser = (new DockerImageParser)->parse('nginx');

        $this->assertSame('', $parser->getRegistryUrl());
        $this->assertSame('nginx', $parser->getImageName());
        $this->assertSame('latest', $parser->getTag());
        $this->assertFalse($parser->isImageHash());
        $this->assertSame('nginx:latest', $parser->toString());
    }

    #[Test]
    public function parses_image_with_explicit_tag()
    {
        $parser = (new DockerImageParser)->parse('nginx:1.25');

        $this->assertSame('nginx', $parser->getImageName());
        $this->assertSame('1.25', $parser->getTag());
        $this->assertSame('nginx:1.25', $parser->toString());
    }

    #[Test]
    public function parses_registry_and_image()
    {
        $parser = (new DockerImageParser)->parse('registry.example.com/myapp:2.0');

        $this->assertSame('registry.example.com', $parser->getRegistryUrl());
        $this->assertSame('myapp', $parser->getImageName());
        $this->assertSame('2.0', $parser->getTag());
        $this->assertSame('registry.example.com/myapp:2.0', $parser->toString());
    }

    #[Test]
    public function parses_registry_with_port()
    {
        $parser = (new DockerImageParser)->parse('registry.example.com:5000/myapp:latest');

        $this->assertSame('registry.example.com:5000', $parser->getRegistryUrl());
        $this->assertSame('myapp', $parser->getImageName());
        $this->assertSame('latest', $parser->getTag());
    }

    #[Test]
    public function colon_in_registry_is_not_a_tag()
    {
        $parser = (new DockerImageParser)->parse('registry:5000/myapp');

        $this->assertSame('registry:5000', $parser->getRegistryUrl());
        $this->assertSame('myapp', $parser->getImageName());
        $this->assertSame('latest', $parser->getTag());
    }

    #[Test]
    public function parses_nested_image_paths()
    {
        $parser = (new DockerImageParser)->parse('registry.example.com/team/project/app:9');

        $this->assertSame('registry.example.com', $parser->getRegistryUrl());
        $this->assertSame('team/project/app', $parser->getImageName());
        $this->assertSame('9', $parser->getTag());
    }

    #[Test]
    public function parses_sha256_digest_format()
    {
        $hash = str_repeat('a', 64);
        $parser = (new DockerImageParser)->parse("nginx@sha256:$hash");

        $this->assertSame('nginx', $parser->getImageName());
        $this->assertSame($hash, $parser->getTag());
        $this->assertTrue($parser->isImageHash());
        $this->assertSame("nginx@sha256:$hash", $parser->toString());
    }

    #[Test]
    public function detects_sha256_tag_as_hash()
    {
        $hash = str_repeat('b', 64);
        $parser = (new DockerImageParser)->parse("myapp:$hash");

        $this->assertSame('myapp', $parser->getImageName());
        $this->assertSame($hash, $parser->getTag());
        $this->assertTrue($parser->isImageHash());
        $this->assertSame("myapp:$hash", $parser->toString());
    }

    #[Test]
    public function non_sha256_tag_is_not_hash()
    {
        $parser = (new DockerImageParser)->parse('myapp:12345');

        $this->assertFalse($parser->isImageHash());
        $this->assertSame('12345', $parser->getTag());
    }

    #[Test]
    public function get_full_image_name_with_hash_returns_correct_format()
    {
        $hash = str_repeat('c', 64);
        $parser = (new DockerImageParser)->parse("repo/app@sha256:$hash");

        $this->assertSame("repo/app@sha256:$hash", $parser->getFullImageNameWithHash());
    }

    #[Test]
    public function get_full_image_name_with_tag_returns_correct_format()
    {
        $parser = (new DockerImageParser)->parse('repo/app:7');

        $this->assertSame('repo/app:7', $parser->getFullImageNameWithHash());
    }

    #[Test]
    public function registry_is_preserved_in_full_image_name()
    {
        $parser = (new DockerImageParser)->parse('registry.example.com/app:3');

        $this->assertSame('registry.example.com/app', $parser->getFullImageNameWithoutTag());
        $this->assertSame('registry.example.com/app:3', $parser->getFullImageNameWithHash());
    }

    #[Test]
    public function to_string_reconstructs_hash_format()
    {
        $hash = str_repeat('d', 64);
        $parser = (new DockerImageParser)->parse("registry:5000/app@sha256:$hash");

        $this->assertSame("registry:5000/app@sha256:$hash", $parser->toString());
    }

    #[Test]
    public function to_string_reconstructs_tag_format()
    {
        $parser = (new DockerImageParser)->parse('registry:5000/app:latest');

        $this->assertSame('registry:5000/app:latest', $parser->toString());
    }
}
