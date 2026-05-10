// Configuration webpack — surcharge l'entrée et la sortie par défaut de
// `@wordpress/scripts` pour cantoner les sources sous `assets/src/` et
// les artefacts compilés sous `assets/build/`.
//
// Convention extension :
//  - sources JSX :   assets/src/admin-spa/
//  - sortie build :  assets/build/admin-spa.{js, asset.php, css}
//  - .gitignore exclut /assets/build/ — chacun rebuild localement.
//
// Le fichier `admin-spa.asset.php` produit par wp-scripts contient
// `[ 'dependencies' => [...], 'version' => '...' ]` consommé par
// `Admin\Assets::on_enqueue()`.
//
// Tout le reste (loaders, plugins, presets Babel, externals des paquets
// `@wordpress/*`) vient des défauts wp-scripts — ne PAS dupliquer ici,
// juste étendre.

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin-spa': './assets/src/admin-spa/index.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
