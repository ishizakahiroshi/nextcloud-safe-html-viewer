import { registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'

const APP_ID = 'safe_html_viewer'

registerFileAction({
	id: 'safe-html-viewer',
	displayName: () => 'Safe HTML preview',
	iconClass: 'icon-category-files',
	mime: 'text/html',
	enabled: (nodes) => {
		if (nodes.length !== 1) return false
		const node = nodes[0]
		const mime = node.mime || ''
		const ext = (node.basename || '').toLowerCase()
		return mime === 'text/html' || ext.endsWith('.html')
	},
	exec: async (nodes) => {
		const node = nodes[0]
		const url = generateUrl(`/apps/${APP_ID}/raw/${node.fileid || node.id}`)
		window.open(url, '_blank', 'noopener')
		return null
	},
})
