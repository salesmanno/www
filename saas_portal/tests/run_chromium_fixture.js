const {spawn} = require('node:child_process');

function runChromium(args, {timeoutMs = 30000, maxBuffer = 4 * 1024 * 1024} = {}) {
    return new Promise(resolve => {
        const child = spawn('chromium', args, {detached: true, stdio: ['ignore', 'pipe', 'pipe']});
        const stdout = [];
        const stderr = [];
        let stdoutBytes = 0;
        let stderrBytes = 0;
        let failure = null;
        let finished = false;
        let killTimer = null;

        const stopGroup = signal => {
            try {
                process.kill(-child.pid, signal);
            } catch (error) {
                if (error.code !== 'ESRCH') {
                    try { child.kill(signal); } catch (_) {}
                }
            }
        };
        const finish = result => {
            if (finished) return;
            finished = true;
            clearTimeout(timeout);
            if (killTimer) clearTimeout(killTimer);
            resolve(result);
        };
        const collect = (chunks, stream) => chunk => {
            if (stream === 'stdout') stdoutBytes += chunk.length;
            else stderrBytes += chunk.length;
            if (stdoutBytes + stderrBytes > maxBuffer && !failure) {
                failure = new Error(`Chromium fixture exceeded ${maxBuffer} output bytes`);
                stopGroup('SIGTERM');
                killTimer = setTimeout(() => stopGroup('SIGKILL'), 1000);
            }
            chunks.push(chunk);
        };

        child.stdout.on('data', collect(stdout, 'stdout'));
        child.stderr.on('data', collect(stderr, 'stderr'));
        child.on('error', error => finish({status: null, stdout: Buffer.concat(stdout).toString(), stderr: Buffer.concat(stderr).toString(), error}));
        child.on('close', (status, signal) => finish({
            status,
            signal,
            stdout: Buffer.concat(stdout).toString(),
            stderr: Buffer.concat(stderr).toString(),
            error: failure
        }));

        const timeout = setTimeout(() => {
            failure = new Error(`Chromium fixture exceeded ${timeoutMs}ms`);
            stopGroup('SIGTERM');
            killTimer = setTimeout(() => stopGroup('SIGKILL'), 1000);
        }, timeoutMs);
    });
}

module.exports = runChromium;
