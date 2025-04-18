// gulpfile.js

const gulp        = require('gulp');
const sass        = require('gulp-sass')(require('sass'));
const cleanCSS    = require('gulp-clean-css');
const uglify      = require('gulp-uglify');
const rename      = require('gulp-rename');
const sourcemaps  = require('gulp-sourcemaps');
const concat      = require('gulp-concat');
const browserSync = require('browser-sync').create();
const del         = require('del');
const gulpIf      = require('gulp-if');
const revAll      = require('gulp-rev');

const isProd = process.env.NODE_ENV === 'production';

const paths = {
  styles: {
    universal: 'src/scss/universal/**/*.scss',
    pages:     'src/scss/pages/**/*.scss',
    devDest:   'styles',
    prodDest:  'dist/styles'
  },
  scripts: {
    universal: 'src/js/universal/**/*.js',
    pages:     'src/js/pages/**/*.js',
    devDest:   'scripts',
    prodDest:  'dist/scripts'
  },
  html: {
    src: 'views/**/*.php'
  },
  manifest: {
    dest: 'dist/manifest'
  },
  assets: 'assets'  // for images, fonts, etc.
};

function clean() {
  return del([ paths.styles.devDest, paths.scripts.devDest, 'dist' ]);
}

function universalStyles() {
  return gulp.src(paths.styles.universal)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(sass().on('error', sass.logError))
    .pipe(concat('global.css'))
    .pipe(gulpIf(isProd, cleanCSS()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulp.dest(isProd ? paths.styles.prodDest : paths.styles.devDest))
    .pipe(browserSync.stream());
}

function pageStyles() {
  return gulp.src(paths.styles.pages)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(sass().on('error', sass.logError))
    .pipe(gulpIf(isProd, cleanCSS()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulp.dest(isProd ? paths.styles.prodDest : paths.styles.devDest))
    .pipe(browserSync.stream());
}

function universalScripts() {
  return gulp.src(paths.scripts.universal)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(concat('universal.js'))
    .pipe(gulpIf(isProd, uglify()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulp.dest(isProd ? paths.scripts.prodDest : paths.scripts.devDest))
    .pipe(browserSync.stream());
}

function pageScripts() {
  return gulp.src(paths.scripts.pages)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(gulpIf(isProd, uglify()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulp.dest(isProd ? paths.scripts.prodDest : paths.scripts.devDest))
    .pipe(browserSync.stream());
}

function revFiles() {
  if (!isProd) return Promise.resolve();
  return gulp.src([
      `${paths.styles.prodDest}/*.min.css`,
      `${paths.scripts.prodDest}/*.min.js`
    ], { base: 'dist' })
    .pipe(revAll())
    .pipe(gulp.dest('dist'))
    .pipe(revAll.manifest('rev-manifest.json'))
    .pipe(gulp.dest(paths.manifest.dest));
}

function watch() {
  browserSync.init({
    proxy:      'localhost:3007',         // your PHP dev server
    notify:     false,
    serveStatic: [
      { route: '/styles',  dir: paths.styles.devDest  },
      { route: '/scripts', dir: paths.scripts.devDest },
      { route: '/assets',  dir: paths.assets          }
    ]
  });

  gulp.watch(paths.styles.universal, universalStyles);
  gulp.watch(paths.styles.pages,     pageStyles);
  gulp.watch(paths.scripts.universal, universalScripts);
  gulp.watch(paths.scripts.pages,     pageScripts);
  gulp.watch(paths.html.src).on('change', browserSync.reload);
}

const styles  = gulp.parallel(universalStyles, pageStyles);
const scripts = gulp.parallel(universalScripts, pageScripts);
const build   = gulp.series(clean, gulp.parallel(styles, scripts), revFiles);
const dev     = gulp.series(build, watch);

exports.clean   = clean;
exports.styles  = styles;
exports.scripts = scripts;
exports.revFiles= revFiles;
exports.watch   = watch;
exports.build   = build;
exports.dev     = dev;
exports.default = dev;
