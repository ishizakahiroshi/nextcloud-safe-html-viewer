const path = require('path');

module.exports = {
	entry: {
		main: path.join(__dirname, 'js/src/main.js'),
	},
	output: {
		path: path.resolve(__dirname, 'js'),
		filename: '[name].js',
	},
	resolve: {
		extensions: ['.js'],
		fallback: {
			stream: false,
		},
	},
};
