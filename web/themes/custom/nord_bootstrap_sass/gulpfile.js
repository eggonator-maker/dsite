let gulp = require('gulp'),
  sass = require('gulp-sass')(require('sass')),
  sourcemaps = require('gulp-sourcemaps'),
  $ = require('gulp-load-plugins')(),
  cleanCss = require('gulp-clean-css'),
  rename = require('gulp-rename'),
  postcss = require('gulp-postcss'),
  autoprefixer = require('autoprefixer'),
  postcssInlineSvg = require('postcss-inline-svg'),
  browserSync = require('browser-sync').create(),
  pxtorem = require('postcss-pxtorem'),
  postcssProcessors = [
    postcssInlineSvg({
      removeFill: true,
      paths: ['./node_modules/bootstrap-icons/icons'],
    }),
    pxtorem({
      propList: [
        'font',
        'font-size',
        'line-height',
        'letter-spacing',
        '*margin*',
        '*padding*',
      ],
      mediaQuery: true,
    }),
  ];

const paths = {
  scss: {
    src: './scss/style.scss',
    dest: './css',
    watch: './scss/**/*.scss',
    bootstrap: './node_modules/bootstrap/scss/bootstrap.scss',
    components: './components/**/*.scss',
    componentsWatch: './components/**/*.scss',
  },
  js: {
    bootstrap: './node_modules/bootstrap/dist/js/bootstrap.min.js',
    popper: './node_modules/@popperjs/core/dist/umd/popper.min.js',
    barrio: '../../contrib/bootstrap_barrio/js/barrio.js',
    dest: './js',
  },
};

// Compile sass into CSS & auto-inject into browsers
function styles() {
  return gulp
    .src([paths.scss.bootstrap, paths.scss.src])
    .pipe(sourcemaps.init())
    .pipe(
      sass({
        outputStyle: 'expanded', // Changed from compressed
        includePaths: [
          './node_modules/bootstrap/scss',
          '../../contrib/bootstrap_barrio/scss',
        ],
        sourceMap: true, // Explicitly enable
        sourceMapEmbed: false, // Don't embed, write to file
        sourceMapContents: false // Don't include source content
      }).on('error', sass.logError)
    )
    .pipe($.postcss(postcssProcessors))
    .pipe(
      postcss([
        autoprefixer({
          cascade: false
        }),
      ])
    )
    .pipe(sourcemaps.write('./maps', {
      includeContent: false,
      sourceRoot: '../scss' // Important: tells browser where to find SCSS files
    }))
    .pipe(gulp.dest(paths.scss.dest))
    .pipe(browserSync.stream());
}

// Create minified version (for production)
function stylesMin() {
  return gulp
    .src([paths.scss.bootstrap, paths.scss.src])
    .pipe(sourcemaps.init())
    .pipe(
      sass({
        outputStyle: 'compressed',
        includePaths: [
          './node_modules/bootstrap/scss',
          '../../contrib/bootstrap_barrio/scss',
        ],
      }).on('error', sass.logError)
    )
    .pipe($.postcss(postcssProcessors))
    .pipe(
      postcss([
        autoprefixer({
          cascade: false
        }),
      ])
    )
    .pipe(cleanCss())
    .pipe(rename({ suffix: '.min' }))
    .pipe(sourcemaps.write('./maps')) // Source maps for minified files too
    .pipe(gulp.dest(paths.scss.dest));
}

function createCssComponent(fileWithLocation) {
  return gulp
    .src([paths.scss.components])
    .pipe(sourcemaps.init())
    .pipe(
      sass({
        outputStyle: 'expanded', // Change to expanded for better debugging
      }).on('error', sass.logError)
    )
    .pipe($.postcss(postcssProcessors))
    .pipe(
      postcss([
        autoprefixer({
          cascade: false
        }),
      ])
    )
    .pipe(sourcemaps.write('./maps')) // Write source maps to maps folder
    .pipe(gulp.dest('./components/' + '.'))
    .pipe(browserSync.stream());
}

// Move the javascript files into our js folder
function js() {
  return gulp
    .src([paths.js.bootstrap, paths.js.popper, paths.js.barrio])
    .pipe(gulp.dest(paths.js.dest))
    .pipe(browserSync.stream());
}

function serve() {
  browserSync.init({
    proxy: 'https://nord.ddev.site',
    host: 'nord.ddev.site',
    open: false,
    notify: false,
    https: true,
    // Add these important options:
    port: 3000,
    ui: {
      port: 3001
    },
    ghostMode: false,
    // Fix for DDEV/proxy issues:
    snippetOptions: {
      rule: {
        match: /<\/body>/i,
        fn: function (snippet, match) {
          return snippet + match;
        }
      }
    },

  });

  gulp
    .watch([paths.scss.watch, paths.scss.bootstrap], styles)
    .on('change', browserSync.reload);
  gulp.watch(paths.scss.componentsWatch, createCssComponent);
}

const build = gulp.series(styles, gulp.parallel(js, serve));
const buildProd = gulp.series(gulp.parallel(styles, stylesMin), js);

exports.styles = styles;
exports.stylesMin = stylesMin;
exports.js = js;
exports.serve = serve;
exports.build = buildProd;

exports.default = build;
