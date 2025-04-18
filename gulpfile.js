const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const cleanCSS = require('gulp-clean-css');
const uglify = require('gulp-uglify');
const rename = require('gulp-rename');
const sourcemaps = require('gulp-sourcemaps');
const concat = require('gulp-concat');
const browserSync = require('browser-sync').create();
const del = require('del');
const gulpIf = require('gulp-if');
const revAll = require('gulp-rev');
const revReplace = require('gulp-rev-replace');
const { dest } = require('vinyl-fs');

// determine environment 
const isProd = process.env.NODE_ENV === 'production';

// file paths
const paths = {
  styles: {
    src: 'src/scss/**/*.scss',
    universal: 'src/scss/universal/**/*.scss',
    pages: 'src/scss/pages/**/*.scss',
    devDest: 'styles',
    prodDest: 'dist/styles'
  },
  scripts: {
    src: 'src/js/**/*.js',
    universal: 'src/js/universal/**/*.js',
    pages: 'src/js/pages/**/*.js',
    devDest: 'scripts',
    prodDest: 'dist/scripts'
  },
  html: {
    src: 'views/**/*.php'
  },
  manifest: {
    dest: 'dist/manifest'
  }
};


// Clean assets
function clean() {
  return del([
    paths.styles.devDest, 
    paths.scripts.devDest, 
    'dist'
  ]);
}

// Process universal CSS
function universalStyles() {
  return gulp.src(paths.styles.universal)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(sass().on('error', sass.logError))
    .pipe(concat('global.css'))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulpIf(!isProd, gulp.dest(paths.styles.devDest)))
    .pipe(gulpIf(isProd, cleanCSS()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(isProd, gulp.dest(paths.styles.prodDest)))
    .pipe(browserSync.stream());
}

// Process page-specific CSS
function pageStyles() {
  return gulp.src(paths.styles.pages)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(sass().on('error', sass.logError))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulpIf(!isProd, gulp.dest(paths.styles.devDest)))
    .pipe(gulpIf(isProd, cleanCSS()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(isProd, gulp.dest(paths.styles.prodDest)))
    .pipe(browserSync.stream());
}

// Process universal JavaScript
function universalScripts() {
  return gulp.src(paths.scripts.universal)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(concat('universal.js'))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulpIf(!isProd, gulp.dest(paths.scripts.devDest)))
    .pipe(gulpIf(isProd, uglify()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(isProd, gulp.dest(paths.scripts.prodDest)))
    .pipe(browserSync.stream());
}

// Process page-specific JavaScript
function pageScripts() {
  return gulp.src(paths.scripts.pages)
    .pipe(gulpIf(!isProd, sourcemaps.init()))
    .pipe(gulpIf(!isProd, sourcemaps.write('.')))
    .pipe(gulpIf(!isProd, gulp.dest(paths.scripts.devDest)))
    .pipe(gulpIf(isProd, uglify()))
    .pipe(gulpIf(isProd, rename({ suffix: '.min' })))
    .pipe(gulpIf(isProd, gulp.dest(paths.scripts.prodDest)))
    .pipe(browserSync.stream());
}

// Revision files to bust cache when in production
function revFiles() {
  if (!isProd) return Promise.resolve();
  
  return gulp.src([
    `${paths.styles.prodDest}/**/*.css`,
    `${paths.scripts.prodDest}/**/*.js`
  ])
    .pipe(revAll())
    .pipe(gulp.dest(function(file) {
      // Return the directory the file came from
      return file.base;
    }))
    .pipe(revAll.manifest('rev-manifest.json'))
    .pipe(gulp.dest(paths.manifest.dest));
}

// Watch files
function watch() {
  browserSync.init({
    proxy: "localhost:3000",
    notify: false
  });
  
  gulp.watch(paths.styles.universal, universalStyles);
  gulp.watch(paths.styles.pages, pageStyles);
  gulp.watch(paths.scripts.universal, universalScripts);
  gulp.watch(paths.scripts.pages, pageScripts);
  gulp.watch(paths.html.src).on('change', browserSync.reload);
}

// Define complex tasks
const styles = gulp.parallel(universalStyles, pageStyles);
const scripts = gulp.parallel(universalScripts, pageScripts);
const build = gulp.series(clean, gulp.parallel(styles, scripts), revFiles);
const dev = gulp.series(build, watch);

// Export tasks
exports.clean = clean;
exports.styles = styles;
exports.scripts = scripts;
exports.revFiles = revFiles;
exports.watch = watch;
exports.build = build;
exports.dev = dev;
exports.default = dev;