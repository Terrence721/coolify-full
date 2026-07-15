<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\GithubApp;
use App\Models\GitlabApp;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Git command generation for deployments: ls-remote/import/checkout command
 * strings for GitHub App, GitLab App, deploy-key, and plain-URL sources, plus
 * the legacy raw-compose parser. Extracted from App\Models\Application.
 */
trait GeneratesGitCommands
{
    public function customRepository(): array
    {
        return convertGitUrl($this->git_repository, $this->deploymentType(), $this->source);
    }

    public function setGitImportSettings(string $deployment_uuid, string $git_clone_command, bool $public = false, ?string $commit = null, ?string $gitSshCommand = null, ?string $git_ssh_command = null, ?string $gitConfigOptions = null)
    {
        $baseDir = $this->generateBaseDir($deployment_uuid);
        $escapedBaseDir = escapeshellarg($baseDir);
        $isShallowCloneEnabled = $this->settings?->is_git_shallow_clone_enabled ?? false;
        $gitCommand = $gitConfigOptions ? "git {$gitConfigOptions}" : 'git';

        $resolvedGitSshCommand = $git_ssh_command ?? $gitSshCommand;
        $sshCommand = $resolvedGitSshCommand
            ? (str_starts_with($resolvedGitSshCommand, 'GIT_SSH_COMMAND=')
                ? $resolvedGitSshCommand
                : 'GIT_SSH_COMMAND="'.$resolvedGitSshCommand.'"')
            : 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"';

        // Use the explicitly passed commit (e.g. from rollback), falling back to the application's git_commit_sha.
        // Invalid refs will cause the git checkout/fetch command to fail on the remote server.
        $commitToUse = $commit ?? $this->git_commit_sha;

        if ($commitToUse !== 'HEAD') {
            $escapedCommit = escapeshellarg($commitToUse);
            // If shallow clone is enabled and we need a specific commit,
            // we need to fetch that specific commit with depth=1
            if ($isShallowCloneEnabled) {
                $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} {$gitCommand} fetch --depth=1 origin {$escapedCommit} && {$gitCommand} -c advice.detachedHead=false checkout {$escapedCommit} >/dev/null 2>&1";
            } else {
                $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} {$gitCommand} -c advice.detachedHead=false checkout {$escapedCommit} >/dev/null 2>&1";
            }
        }
        if (data_get($this, 'settings.is_git_submodules_enabled')) {
            // Check if .gitmodules file exists before running submodule commands
            $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && if [ -f .gitmodules ]; then";
            if ($public) {
                $git_clone_command = "{$git_clone_command} sed -i \"s#git@\(.*\):#https://\\1/#g\" {$escapedBaseDir}/.gitmodules || true &&";
            }
            // Add shallow submodules flag if shallow clone is enabled
            $submoduleFlags = $isShallowCloneEnabled ? '--depth=1' : '';
            $git_clone_command = "{$git_clone_command} {$gitCommand} submodule sync && {$sshCommand} {$gitCommand} submodule update --init --recursive {$submoduleFlags}; fi";
        }
        if (data_get($this, 'settings.is_git_lfs_enabled')) {
            $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} {$gitCommand} lfs pull";
        }

        return $git_clone_command;
    }

    public function getGitRemoteStatus(string $deployment_uuid)
    {
        try {
            ['commands' => $lsRemoteCommand] = $this->generateGitLsRemoteCommands(deployment_uuid: $deployment_uuid, exec_in_docker: false);
            $server = data_get($this, 'destination.server');
            instant_remote_process([$lsRemoteCommand], $server, true);

            return [
                'is_accessible' => true,
                'error' => null,
            ];
        } catch (RuntimeException $ex) {
            return [
                'is_accessible' => false,
                'error' => $ex->getMessage(),
            ];
        }
    }

    public function generateGitLsRemoteCommands(string $deployment_uuid, bool $exec_in_docker = true)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $commands = collect([]);
        $customSshKeyLocation = "/root/.ssh/id_rsa_coolify_{$deployment_uuid}";
        $base_command = 'git ls-remote';

        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                $escapedCustomRepository = escapeshellarg($customRepository);
                if (data_get($this, 'source.is_public')) {
                    $sourceHtmlUrl = data_get($this, 'source.html_url');
                    $escapedRepoUrl = escapeshellarg("{$sourceHtmlUrl}/{$customRepository}");
                    $fullRepoUrl = "{$sourceHtmlUrl}/{$customRepository}";
                    $base_command = "{$base_command} {$escapedRepoUrl}";
                } else {
                    $github_access_token = generateGithubInstallationToken($this->source);
                    $encodedToken = rawurlencode($github_access_token);

                    if ($exec_in_docker) {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}.git";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $base_command = "{$base_command} {$escapedRepoUrl}";
                        $fullRepoUrl = $repoUrl;
                    } else {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $base_command = "{$base_command} {$escapedRepoUrl}";
                        $fullRepoUrl = $repoUrl;
                    }
                }

                if ($exec_in_docker) {
                    $commands->push(executeInDocker($deployment_uuid, $base_command));
                } else {
                    $commands->push($base_command);
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }

            if ($this->source->getMorphClass() === GitlabApp::class) {
                $gitlabSource = $this->source;
                $private_key = data_get($gitlabSource, 'privateKey.private_key');

                if ($private_key) {
                    $fullRepoUrl = $customRepository;
                    $private_key = base64_encode($private_key);
                    $gitlabPort = $gitlabSource->custom_port ?? 22;
                    $escapedCustomRepository = str_replace("'", "'\\''", $customRepository);
                    $base_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes\" {$base_command} '{$escapedCustomRepository}'";

                    if ($exec_in_docker) {
                        $commands = collect([
                            executeInDocker($deployment_uuid, 'mkdir -p /root/.ssh'),
                            executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null"),
                            executeInDocker($deployment_uuid, "chmod 600 {$customSshKeyLocation}"),
                        ]);
                    } else {
                        $commands = collect([
                            "trap 'rm -f {$customSshKeyLocation}' EXIT",
                            'mkdir -p /root/.ssh',
                            "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null",
                            "chmod 600 {$customSshKeyLocation}",
                        ]);
                    }

                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $base_command));
                    } else {
                        $commands->push($base_command);
                    }

                    return [
                        'commands' => $commands->implode(' && '),
                        'branch' => $branch,
                        'fullRepoUrl' => $fullRepoUrl,
                    ];
                }

                // GitLab source without private key — use URL as-is (supports user-embedded basic auth)
                $fullRepoUrl = $customRepository;
                $escapedCustomRepository = escapeshellarg($customRepository);
                $base_command = "{$base_command} {$escapedCustomRepository}";

                if ($exec_in_docker) {
                    $commands->push(executeInDocker($deployment_uuid, $base_command));
                } else {
                    $commands->push($base_command);
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }
        }

        if ($this->deploymentType() === 'deploy_key') {
            $fullRepoUrl = $customRepository;
            $private_key = data_get($this, 'private_key.private_key');
            if (is_null($private_key)) {
                throw new RuntimeException('Private key not found. Please add a private key to the application and try again.');
            }
            $private_key = base64_encode($private_key);
            // When used with executeInDocker (which uses bash -c '...'), we need to escape for bash context
            // Replace ' with '\'' to safely escape within single-quoted bash strings
            $escapedCustomRepository = str_replace("'", "'\\''", $customRepository);
            $base_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes\" {$base_command} '{$escapedCustomRepository}'";

            if ($exec_in_docker) {
                $commands = collect([
                    executeInDocker($deployment_uuid, 'mkdir -p /root/.ssh'),
                    executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null"),
                    executeInDocker($deployment_uuid, "chmod 600 {$customSshKeyLocation}"),
                ]);
            } else {
                $commands = collect([
                    "trap 'rm -f {$customSshKeyLocation}' EXIT",
                    'mkdir -p /root/.ssh',
                    "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null",
                    "chmod 600 {$customSshKeyLocation}",
                ]);
            }

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $base_command));
            } else {
                $commands->push($base_command);
            }

            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }

        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $escapedCustomRepository = escapeshellarg($customRepository);
            $base_command = "{$base_command} {$escapedCustomRepository}";

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $base_command));
            } else {
                $commands->push($base_command);
            }

            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
    }

    private function withGitHttpTransportConfig(?string $gitConfigOptions = null): string
    {
        return trim(($gitConfigOptions ? "{$gitConfigOptions} " : '').'-c http.version=HTTP/1.1');
    }

    private function isHttpGitRepository(string $repository): bool
    {
        return str_starts_with($repository, 'https://') || str_starts_with($repository, 'http://');
    }

    private function applyGitConfigOptionsToCloneCommand(string $gitCloneCommand, string $gitConfigOptions): string
    {
        $configuredCommand = preg_replace(
            "/^git(?:\s+-c\s+(?:'[^']*'|\S+))*\s+clone\b/",
            "git {$gitConfigOptions} clone",
            $gitCloneCommand,
            1
        );

        return $configuredCommand ?: $gitCloneCommand;
    }

    public function generateGitImportCommands(string $deployment_uuid, int $pull_request_id = 0, ?string $git_type = null, bool $exec_in_docker = true, bool $only_checkout = false, ?string $custom_base_dir = null, ?string $commit = null)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $baseDir = $custom_base_dir ?? $this->generateBaseDir($deployment_uuid);
        $customSshKeyLocation = "/root/.ssh/id_rsa_coolify_{$deployment_uuid}";

        // Escape shell arguments for safety to prevent command injection
        $escapedBranch = escapeshellarg($branch);
        $escapedBaseDir = escapeshellarg($baseDir);

        $commands = collect([]);

        // Check if shallow clone is enabled
        $isShallowCloneEnabled = $this->settings?->is_git_shallow_clone_enabled ?? false;
        $depthFlag = $isShallowCloneEnabled ? ' --depth=1' : '';

        $submoduleFlags = '';
        if (data_get($this, 'settings.is_git_submodules_enabled')) {
            $submoduleFlags = ' --recurse-submodules';
            if ($isShallowCloneEnabled) {
                $submoduleFlags .= ' --shallow-submodules';
            }
        }

        $git_clone_command = "git clone{$depthFlag}{$submoduleFlags} -b {$escapedBranch}";
        if ($only_checkout) {
            $git_clone_command = "git clone{$depthFlag}{$submoduleFlags} --no-checkout -b {$escapedBranch}";
        }
        $pr_branch_name = '';
        if ($pull_request_id !== 0) {
            $pr_branch_name = "pr-{$pull_request_id}-coolify";
        }
        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() === GithubApp::class) {
                if (data_get($this, 'source.is_public')) {
                    $sourceHtmlUrl = data_get($this, 'source.html_url');
                    $fullRepoUrl = "{$sourceHtmlUrl}/{$customRepository}";
                    $escapedRepoUrl = escapeshellarg("{$sourceHtmlUrl}/{$customRepository}");
                    $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                    $gitConfigOptions = $this->withGitHttpTransportConfig();
                    $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit, gitConfigOptions: $gitConfigOptions);
                    }
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }
                } else {
                    $github_access_token = generateGithubInstallationToken($this->source);
                    $encodedToken = rawurlencode($github_access_token);

                    // Rewrite same-host HTTPS URLs only for these git commands so submodules can authenticate without persisting credentials.
                    $gitConfigOption = '-c '.escapeshellarg("url.{$source_html_url_scheme}://x-access-token:{$encodedToken}@{$source_html_url_host}/.insteadOf={$source_html_url_scheme}://{$source_html_url_host}/");
                    $gitConfigOptions = $this->withGitHttpTransportConfig($gitConfigOption);
                    $git_clone_command = str_replace('git clone', "git {$gitConfigOption} clone", $git_clone_command);

                    if ($exec_in_docker) {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}.git";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                        $fullRepoUrl = $repoUrl;
                    } else {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                        $fullRepoUrl = $repoUrl;
                    }
                    $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: false, commit: $commit, gitConfigOptions: $gitConfigOptions);
                    }
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }
                }
                if ($pull_request_id !== 0) {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";

                    $git_checkout_command = $this->buildGitCheckoutCommand($pr_branch_name, gitConfigOptions: $gitConfigOptions);
                    $gitCommand = "git {$gitConfigOptions}";
                    $escapedPrBranch = escapeshellarg($branch);
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "cd {$escapedBaseDir} && {$gitCommand} fetch origin {$escapedPrBranch} && $git_checkout_command"));
                    } else {
                        $commands->push("cd {$escapedBaseDir} && {$gitCommand} fetch origin {$escapedPrBranch} && $git_checkout_command");
                    }
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }

            if ($this->source->getMorphClass() === GitlabApp::class) {
                $gitlabSource = $this->source;
                $private_key = data_get($gitlabSource, 'privateKey.private_key');

                if ($private_key) {
                    $fullRepoUrl = $customRepository;
                    $private_key = base64_encode($private_key);
                    $gitlabPort = $gitlabSource->custom_port ?? 22;
                    $escapedCustomRepository = escapeshellarg($customRepository);
                    $gitlabSshCommand = "ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes";
                    $gitlabGitSshCommand = "GIT_SSH_COMMAND=\"{$gitlabSshCommand}\"";
                    $git_clone_command_base = "{$gitlabGitSshCommand} {$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
                    if ($only_checkout) {
                        $git_clone_command = $git_clone_command_base;
                    } else {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base, commit: $commit, gitSshCommand: $gitlabSshCommand);
                    }
                    if ($exec_in_docker) {
                        $commands = collect([
                            executeInDocker($deployment_uuid, 'mkdir -p /root/.ssh'),
                            executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null"),
                            executeInDocker($deployment_uuid, "chmod 600 {$customSshKeyLocation}"),
                        ]);
                    } else {
                        $commands = collect([
                            "trap 'rm -f {$customSshKeyLocation}' EXIT",
                            'mkdir -p /root/.ssh',
                            "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null",
                            "chmod 600 {$customSshKeyLocation}",
                        ]);
                    }

                    if ($pull_request_id !== 0) {
                        $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                        if ($exec_in_docker) {
                            $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                        } else {
                            $commands->push("echo 'Checking out $branch'");
                        }
                        $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$gitlabGitSshCommand} git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $gitlabSshCommand);
                    }

                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                    } else {
                        $commands->push($git_clone_command);
                    }

                    return [
                        'commands' => $commands->implode(' && '),
                        'branch' => $branch,
                        'fullRepoUrl' => $fullRepoUrl,
                    ];
                }

                // GitLab source without private key — use URL as-is (supports user-embedded basic auth)
                $fullRepoUrl = $customRepository;
                $escapedCustomRepository = escapeshellarg($customRepository);
                $git_clone_command = "{$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
                $gitConfigOptions = $this->isHttpGitRepository($customRepository) ? $this->withGitHttpTransportConfig() : null;
                if ($gitConfigOptions) {
                    $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
                }
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit, gitConfigOptions: $gitConfigOptions);

                if ($exec_in_docker) {
                    $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
                } else {
                    $commands->push($git_clone_command);
                }

                return [
                    'commands' => $commands->implode(' && '),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }
        }
        if ($this->deploymentType() === 'deploy_key') {
            $fullRepoUrl = $customRepository;
            $private_key = data_get($this, 'private_key.private_key');
            if (is_null($private_key)) {
                throw new RuntimeException('Private key not found. Please add a private key to the application and try again.');
            }
            $private_key = base64_encode($private_key);
            $escapedCustomRepository = escapeshellarg($customRepository);
            $deployKeySshCommand = "ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes";
            $deployKeyGitSshCommand = "GIT_SSH_COMMAND=\"{$deployKeySshCommand}\"";
            $git_clone_command_base = "{$deployKeyGitSshCommand} {$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
            if ($only_checkout) {
                $git_clone_command = $git_clone_command_base;
            } else {
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base, commit: $commit, gitSshCommand: $deployKeySshCommand);
            }
            if ($exec_in_docker) {
                $commands = collect([
                    executeInDocker($deployment_uuid, 'mkdir -p /root/.ssh'),
                    executeInDocker($deployment_uuid, "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null"),
                    executeInDocker($deployment_uuid, "chmod 600 {$customSshKeyLocation}"),
                ]);
            } else {
                $commands = collect([
                    "trap 'rm -f {$customSshKeyLocation}' EXIT",
                    'mkdir -p /root/.ssh',
                    "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null",
                    "chmod 600 {$customSshKeyLocation}",
                ]);
            }
            if ($pull_request_id !== 0) {
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$deployKeyGitSshCommand} git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $deployKeySshCommand);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$deployKeyGitSshCommand} git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $deployKeySshCommand);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$deployKeyGitSshCommand} ".$this->buildGitCheckoutCommand($commit, $deployKeySshCommand);
                }
            }

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
            } else {
                $commands->push($git_clone_command);
            }

            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $escapedCustomRepository = escapeshellarg($customRepository);
            $git_clone_command = "{$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
            $gitConfigOptions = $this->isHttpGitRepository($customRepository) ? $this->withGitHttpTransportConfig() : null;
            if ($gitConfigOptions) {
                $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
            }
            $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit, gitConfigOptions: $gitConfigOptions);
            $otherSshCommand = "ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa";

            if ($pull_request_id !== 0) {
                $gitCommand = isset($gitConfigOptions) ? "git {$gitConfigOptions}" : 'git';
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"{$otherSshCommand}\" {$gitCommand} fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $otherSshCommand, $gitConfigOptions);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"{$otherSshCommand}\" {$gitCommand} fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $otherSshCommand, $gitConfigOptions);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push(executeInDocker($deployment_uuid, "echo 'Checking out $branch'"));
                    } else {
                        $commands->push("echo 'Checking out $branch'");
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"{$otherSshCommand}\" ".$this->buildGitCheckoutCommand($commit, $otherSshCommand, $gitConfigOptions);
                }
            }

            if ($exec_in_docker) {
                $commands->push(executeInDocker($deployment_uuid, $git_clone_command));
            } else {
                $commands->push($git_clone_command);
            }

            return [
                'commands' => $commands->implode(' && '),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
    }

    public function oldRawParser()
    {
        try {
            $yaml = Yaml::parse($this->docker_compose_raw);
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
        $services = data_get($yaml, 'services');

        $commands = collect([]);
        $services = collect($services)->map(function ($service) use ($commands) {
            $serviceVolumes = collect(data_get($service, 'volumes', []));
            if ($serviceVolumes->count() > 0) {
                foreach ($serviceVolumes as $volume) {
                    $workdir = $this->workdir();
                    $type = null;
                    $source = null;
                    if (is_string($volume)) {
                        $source = str($volume)->before(':');
                        if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                            $type = str('bind');
                        }
                    } elseif (is_array($volume)) {
                        $type = data_get_str($volume, 'type');
                        $source = data_get_str($volume, 'source');
                    }
                    if ($type?->value() === 'bind') {
                        if ($source->value() === '/var/run/docker.sock') {
                            continue;
                        }
                        if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                            continue;
                        }
                        if ($source->startsWith('.')) {
                            $source = $source->after('.');
                            $source = $workdir.$source;
                        }
                        $commands->push("mkdir -p $source > /dev/null 2>&1 || true");
                    }
                }
            }
            $labels = collect(data_get($service, 'labels', []));
            if (! $labels->contains('coolify.managed')) {
                $labels->push('coolify.managed=true');
            }
            if (! $labels->contains('coolify.applicationId')) {
                $labels->push('coolify.applicationId='.$this->id);
            }
            if (! $labels->contains('coolify.type')) {
                $labels->push('coolify.type=application');
            }
            data_set($service, 'labels', $labels->toArray());

            return $service;
        });
        data_set($yaml, 'services', $services->toArray());
        $this->docker_compose_raw = Yaml::dump($yaml, 10, 2);

        $server = data_get($this, 'destination.server');
        instant_remote_process($commands, $server, false);
    }

    protected function buildGitCheckoutCommand(string $target, ?string $gitSshCommand = null, ?string $gitConfigOptions = null): string
    {
        $escapedTarget = escapeshellarg($target);
        $gitCommand = $gitConfigOptions ? "git {$gitConfigOptions}" : 'git';
        $command = "{$gitCommand} checkout {$escapedTarget}";

        if (data_get($this, 'settings.is_git_submodules_enabled')) {
            $sshCommand = $gitSshCommand ?? 'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
            $command .= " && GIT_SSH_COMMAND=\"{$sshCommand}\" {$gitCommand} submodule update --init --recursive";
        }

        return $command;
    }
}
