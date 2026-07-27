#!/usr/bin/env node
/**
 * Regenerate NOTICES.md from the packages webpack actually puts into js/main.js.
 *
 * The list is taken from a webpack stats run, not from the dependency tree: the three
 * entry packages pull in a closure of 59 packages, of which tree shaking keeps 17. Only
 * what ships needs attributing, and attributing more than ships misstates the artifact.
 *
 * The stats build writes to a temp directory, so the tracked js/main.js is never touched.
 *
 * usage: node scripts/generate-notices.mjs [--check]
 *   --check  exit 1 when NOTICES.md is out of date instead of rewriting it
 */

import { execFileSync } from 'node:child_process'
import { mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync, existsSync, statSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT = join(ROOT, 'NOTICES.md')
const WORK = join(ROOT, 'node_modules', '.cache', 'notices')

const LICENCE_FILES = [
	'LICENSE', 'LICENSE.md', 'LICENSE.txt', 'LICENCE', 'LICENCE.md', 'LICENCE.txt',
	'COPYING', 'COPYING.md', 'COPYING.txt',
]

function bundledPackages() {
	rmSync(WORK, { recursive: true, force: true })
	mkdirSync(WORK, { recursive: true })
	const stats = join(WORK, 'stats.json')
	// The webpack-cli entry point is run directly rather than through the npx/webpack
	// shim: Node refuses to spawn a .cmd without a shell on Windows, and going through a
	// shell would mean quoting these paths by hand.
	execFileSync(
		process.execPath,
		[
			join(ROOT, 'node_modules', 'webpack-cli', 'bin', 'cli.js'),
			'--mode', 'production',
			'--json', stats,
			'--output-path', join(WORK, 'out'),
		],
		{ cwd: ROOT, stdio: ['ignore', 'ignore', 'inherit'] }
	)

	const parsed = JSON.parse(readFileSync(stats, 'utf8'))
	const names = new Set()
	const visit = (modules) => {
		for (const m of modules ?? []) {
			const id = (m.nameForCondition ?? m.identifier ?? m.name ?? '').replace(/\\/g, '/')
			const at = id.lastIndexOf('node_modules/')
			if (at >= 0) {
				const parts = id.slice(at + 'node_modules/'.length).split('/')
				names.add(parts[0].startsWith('@') ? `${parts[0]}/${parts[1]}` : parts[0])
			}
			visit(m.modules)
		}
	}
	for (const chunk of parsed.chunks ?? []) {
		visit(chunk.modules)
	}
	visit(parsed.modules)
	rmSync(WORK, { recursive: true, force: true })
	return [...names].sort()
}

function readText(path) {
	return readFileSync(path, 'utf8').replace(/\r\n/g, '\n').trimEnd()
}

/** Licence texts a package ships: a top-level file, or a REUSE-style LICENSES/ directory. */
function licenceTexts(dir) {
	const found = []
	for (const name of LICENCE_FILES) {
		const path = join(dir, name)
		if (existsSync(path) && statSync(path).isFile()) {
			found.push({ source: name, text: readText(path) })
			break
		}
	}
	const reuse = join(dir, 'LICENSES')
	if (existsSync(reuse) && statSync(reuse).isDirectory()) {
		for (const name of readdirSync(reuse).sort()) {
			found.push({ source: `LICENSES/${name}`, text: readText(join(reuse, name)) })
		}
	}
	return found
}

function spdx(manifest) {
	if (typeof manifest.license === 'string') {
		return manifest.license
	}
	if (typeof manifest.license?.type === 'string') {
		return manifest.license.type
	}
	if (Array.isArray(manifest.licenses)) {
		return manifest.licenses.map((l) => l.type ?? l).join(' OR ')
	}
	return null
}

/** npm allows git URLs and "owner/repo" shorthands where a browsable link is wanted. */
function browsable(value) {
	if (!value) {
		return null
	}
	if (/^https?:\/\//.test(value)) {
		return value
	}
	const git = value
		.replace(/^git\+/, '')
		.replace(/^git@([^:]+):/, 'https://$1/')
		.replace(/^(?:git|ssh):\/\/(?:git@)?/, 'https://')
		.replace(/\.git$/, '')
	if (/^https?:\/\//.test(git)) {
		return git
	}
	return /^[\w.-]+\/[\w.-]+$/.test(value) ? `https://github.com/${value}` : null
}

const packages = bundledPackages().map((name) => {
	const dir = join(ROOT, 'node_modules', name)
	const manifest = JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8'))
	const repository = typeof manifest.repository === 'string'
		? manifest.repository
		: manifest.repository?.url
	return {
		name,
		version: manifest.version,
		spdx: spdx(manifest),
		homepage: browsable(manifest.homepage) ?? browsable(repository),
		texts: licenceTexts(dir),
	}
})

// Identical text means identical copyright holder, so one copy covers every package
// that ships it. Keeps the file readable without dropping a required notice.
const groups = new Map()
for (const p of packages) {
	for (const { source, text } of p.texts) {
		if (!groups.has(text)) {
			groups.set(text, { packages: new Set(), sources: new Set() })
		}
		groups.get(text).packages.add(`${p.name} ${p.version}`)
		groups.get(text).sources.add(source)
	}
}

const lines = []
lines.push('# Third-party notices')
lines.push('')
lines.push('`js/main.js` — tracked here and shipped in every release tarball — is a webpack')
lines.push('bundle. It contains code from the packages below, which keep their own licences.')
lines.push('The rest of this app is AGPL-3.0-or-later; see [LICENSE](./LICENSE).')
lines.push('')
lines.push('The list comes from a webpack stats run, so it is what the bundle actually holds')
lines.push('after tree shaking — not the whole dependency tree. Regenerate with')
lines.push('`npm run notices`; `npm run notices:check` fails when this file is out of date.')
lines.push('')
lines.push('Webpack also extracts the licence banners it finds in the sources into')
lines.push('`js/main.js.LICENSE.txt`, which ships alongside the bundle.')
lines.push('')
lines.push('## Bundled packages')
lines.push('')
lines.push('| Package | Version | License |')
lines.push('|---|---|---|')
for (const p of packages) {
	lines.push(`| ${p.homepage ? `[${p.name}](${p.homepage})` : p.name} | ${p.version} | ${p.spdx ?? 'not declared'} |`)
}
lines.push('')

const withoutText = packages.filter((p) => p.texts.length === 0)
if (withoutText.length > 0) {
	lines.push('These packages ship no licence file, so the identifier above is the only')
	lines.push('statement they make:')
	lines.push('')
	for (const p of withoutText) {
		lines.push(`- ${p.name} ${p.version}`)
	}
	lines.push('')
}

lines.push('## License texts')
lines.push('')
const ordered = [...groups.entries()].sort((a, b) => {
	const an = [...a[1].packages].sort()[0]
	const bn = [...b[1].packages].sort()[0]
	return an.localeCompare(bn)
})
for (const [text, meta] of ordered) {
	lines.push(`### ${[...meta.packages].sort().join(', ')}`)
	lines.push('')
	lines.push(`From ${[...meta.sources].sort().join(', ')}.`)
	lines.push('')
	lines.push('```')
	lines.push(text)
	lines.push('```')
	lines.push('')
}

const rendered = lines.join('\n')

if (process.argv.includes('--check')) {
	const current = existsSync(OUT) ? readFileSync(OUT, 'utf8') : ''
	if (current !== rendered) {
		console.error('NOTICES.md is out of date. Run: npm run notices')
		process.exit(1)
	}
	console.log(`NOTICES.md is up to date (${packages.length} bundled packages).`)
} else {
	writeFileSync(OUT, rendered, 'utf8')
	console.log(`NOTICES.md written: ${packages.length} bundled packages, ${groups.size} licence texts.`)
}
