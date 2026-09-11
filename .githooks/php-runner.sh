#!/bin/sh
#
# Shared by the pre-commit and pre-push hooks.
#
# Gäld's PHP tooling lives in the Sail container, and plenty of machines that
# develop this project have no PHP on the host at all. Calling ./vendor/bin/pint
# directly fails there with "env: 'php': No such file or directory", which a
# hook that only inspects the exit code reports as a style violation — a
# confusing message for a missing interpreter, and one that blocks every commit.
#
# run_php_tool runs the tool wherever it can actually run, and returns 127 when
# there is no runtime at all so callers can tell "could not check" apart from
# "the check failed".

RUN_PHP_TOOL_UNAVAILABLE=127

run_php_tool() {
    if command -v php > /dev/null 2>&1; then
        "$@"
        return $?
    fi

    if docker compose ps --services --status running 2>/dev/null | grep -qx 'laravel.test'; then
        docker compose exec -T laravel.test "$@"
        return $?
    fi

    echo ""
    echo "⚠️  Cannot run ${1}: no PHP on this host and the laravel.test container is not running."
    echo "   Start the dev environment with ./gaeld up, or bypass this hook with --no-verify."
    return $RUN_PHP_TOOL_UNAVAILABLE
}
