import { registerFileAction, DefaultType } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const APP_ID = 'safe_html_viewer'

function isHtmlNode(node) {
	if (!node) return false
	const name = (node.basename || node.attributes?.name || '').toLowerCase()
	const mime = node.mime || node.attributes?.['mime-type'] || ''
	return name.endsWith('.html') || name.endsWith('.htm')
		|| mime === 'text/html' || mime === 'application/xhtml+xml'
}

registerFileAction({
	id: 'safe-html-viewer',
	displayName: () => t(APP_ID, 'Safe HTML preview'),
	iconSvgInline: () => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" fill="currentColor"/></svg>',
	enabled: (ctx) => {
		const nodes = ctx?.nodes ?? (Array.isArray(ctx) ? ctx : [ctx])
		return nodes.length > 0 && nodes.every(isHtmlNode)
	},
	default: DefaultType.DEFAULT,
	order: -100,
	exec: async (nodeOrCtx) => {
		const node = nodeOrCtx?.nodes?.[0] ?? nodeOrCtx
		// Prefer fileid; fall back to id as digit string (avoid parseInt on large snowflake ids)
		const rawId = node?.fileid ?? node?.attributes?.fileid ?? node?.id
		if (rawId === undefined || rawId === null || rawId === '') {
			return null
		}
		let fileId
		if (typeof rawId === 'number') {
			if (!Number.isFinite(rawId) || !Number.isInteger(rawId) || rawId <= 0) {
				return null
			}
			fileId = rawId
		} else {
			const s = String(rawId)
			if (!/^\d+$/.test(s) || s === '0') {
				return null
			}
			fileId = s
		}
		const url = generateUrl(`/apps/${APP_ID}/raw/${fileId}`)
		window.open(url, '_blank', 'noopener,noreferrer')
		return null
	},
})
