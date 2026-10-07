// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright 2026 CodeFortex
// Rebuild only the reviewed Moodle uploader. Dependencies are pinned in scripts/amd.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {createRequire} = require('node:module');
const requireBuild = createRequire(path.join(__dirname, 'amd/package.json'));
const babel = requireBuild('@babel/core');
const terser = requireBuild('terser');
const root = path.resolve(__dirname, '..');
const destination = path.resolve(process.argv[2] || path.join(root, 'plugin/mod_videolesson/amd/build'));

(async() => {
    const source = fs.readFileSync(path.join(root, 'plugin/mod_videolesson/amd/src/upload.js'), 'utf8');
    const transformed = babel.transformSync(source, {
        filename: 'upload.js', moduleId: 'mod_videolesson/upload',
        babelrc: false, configFile: false,
        plugins: [requireBuild.resolve('@babel/plugin-transform-modules-amd')],
        sourceMaps: true, sourceFileName: '../src/upload.js',
    });
    const result = await terser.minify(transformed.code, {
        sourceMap: {content: transformed.map, filename: 'upload.min.js', url: 'upload.min.js.map'},
        format: {comments: false},
    });
    fs.mkdirSync(destination, {recursive: true});
    const hashes = {};
    for (const [name, contents] of [['upload.min.js', result.code], ['upload.min.js.map', result.map]]) {
        const bytes = Buffer.from(contents + '\n');
        fs.writeFileSync(path.join(destination, name), bytes);
        hashes[name] = crypto.createHash('sha256').update(bytes).digest('hex');
    }
    console.log(JSON.stringify({node: process.version, babel: babel.version,
        transformModulesAmd: requireBuild('@babel/plugin-transform-modules-amd/package.json').version,
        terser: requireBuild('terser/package.json').version, hashes}));
})().catch(() => { console.error('Uploader AMD build failed'); process.exitCode = 1; });
