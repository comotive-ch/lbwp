/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, {
/******/ 				configurable: false,
/******/ 				enumerable: true,
/******/ 				get: getter
/******/ 			});
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = "./src/index.js");
/******/ })
/************************************************************************/
/******/ ({

/***/ "../block-helper.js":
/*!**************************!*\
  !*** ../block-helper.js ***!
  \**************************/
/*! exports provided: LbwpBlockHelper */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony export (binding) */ __webpack_require__.d(__webpack_exports__, "LbwpBlockHelper", function() { return LbwpBlockHelper; });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);

var Fragment = wp.element.Fragment;
var _wp$components = wp.components,
    Button = _wp$components.Button,
    Placeholder = _wp$components.Placeholder;
var _wp$editor = wp.editor,
    MediaUpload = _wp$editor.MediaUpload,
    MediaUploadCheck = _wp$editor.MediaUploadCheck;
/**
 * Main Block Helper Library
 * @author Michael Sebel <michael@comotive.ch>
 */

var LbwpBlockHelper = {
  /**
   * Basic image upload component
   * @param url the image url to be displayed
   * @param onsave a saving callback
   * @param onremove a removing callback
   * @param description the text displayed above the button
   * @param button the text displayed in the button
   * @param change the text when the button can change the image
   * @param remove the text when the image can be removed
   * @returns {*}
   */
  getImageUpload: function getImageUpload(url, onsave, onremove, description, button, change, remove) {
    return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(MediaUploadCheck, null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(MediaUpload, {
      onSelect: onsave,
      render: function render(_ref) {
        var open = _ref.open;

        if (url.length > 0) {
          // Elements in state where something is selected
          return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Fragment, null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])("div", null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])("img", {
            src: url
          })), Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Button, {
            onClick: open,
            style: {
              marginRight: '10px'
            },
            isLarge: true,
            isDefault: true
          }, change), Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Button, {
            onClick: onremove,
            isLink: true,
            isDestructive: true
          }, remove));
        } // Elements in initial placeholder state


        return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Fragment, null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Placeholder, {
          icon: "format-image",
          label: description
        }, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Button, {
          onClick: open,
          isLarge: true,
          isDefault: true
        }, button)));
      }
    }));
  },

  /**
   * Basic image upload component
   * @param ids the image ids
   * @param urls the image urls
   * @param onsave a saving callback
   * @param description the text displayed above the button
   * @param button the text displayed in the button to open the gallery
   * @param change the text displayed in the button to edit the gallery
   * @returns {*}
   */
  getGalleryUpload: function getGalleryUpload(ids, urls, onsave, description, button, change) {
    // Suggest empty string if empty array (as empty array or '0' trigger a gallery with all images, bug of gutenberg as of 14.10)
    if (ids.length == 0) ids = '';
    return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(MediaUploadCheck, null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(MediaUpload, {
      onSelect: onsave,
      multiple: "true",
      gallery: "true",
      value: ids,
      render: function render(_ref2) {
        var open = _ref2.open;

        if (ids.length > 0 && urls.length > 0) {
          // Build the array of images
          var images = [];
          urls.forEach(function (url) {
            images.push(Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])("li", null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])("img", {
              src: url
            })));
          }); // Elements in state where something is selected

          return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Fragment, null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])("ul", {
            className: "gallery-list"
          }, images), Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Button, {
            onClick: open,
            isLarge: true,
            isDefault: true
          }, change));
        } // Elements in initial placeholder state


        return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Fragment, null, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Placeholder, {
          icon: "format-image",
          label: description
        }, Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])(Button, {
          onClick: open,
          isLarge: true,
          isDefault: true
        }, button)));
      }
    }));
  }
};


/***/ }),

/***/ "./src/index.js":
/*!**********************!*\
  !*** ./src/index.js ***!
  \**********************/
/*! no exports provided */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _block_helper__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../../block-helper */ "../block-helper.js");

var registerBlockType = wp.blocks.registerBlockType;

registerBlockType('lbwp/slider', {
  title: 'Slider-Galerie',
  icon: 'format-gallery',
  category: 'common',
  attributes: {
    imageIds: {
      type: 'array',
      default: []
    },
    imageUrls: {
      type: 'array',
      default: []
    }
  },
  edit: function edit(props) {
    var onChangeGallerySelection = function onChangeGallerySelection(images) {
      setAttributes({
        imageIds: images.map(function (image) {
          return image.id;
        }),
        imageUrls: images.map(function (image) {
          if (typeof image.sizes.medium !== 'undefined') {
            return image.sizes.medium.url;
          } else if (typeof image.sizes.large !== 'undefined') {
            return image.sizes.large.url;
          } else if (typeof image.sizes.full !== 'undefined') {
            return image.sizes.full.url;
          } else {
            return image.sizes.thumbnail.url;
          }
        })
      });
    };

    var attributes = props.attributes,
        className = props.className,
        setAttributes = props.setAttributes;
    return Object(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__["createElement"])("div", {
      className: className
    }, _block_helper__WEBPACK_IMPORTED_MODULE_1__["LbwpBlockHelper"].getGalleryUpload(attributes.imageIds, attributes.imageUrls, onChangeGallerySelection, 'Wählen Sie in der Mediathek die Bilder für Ihre Galerie aus.', 'Mediathek öffnen', 'Galerie bearbeiten'));
  },
  save: function save(props) {
    return null;
  }
});

/***/ }),

/***/ "@wordpress/element":
/*!******************************************!*\
  !*** external {"this":["wp","element"]} ***!
  \******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

(function() { module.exports = this["wp"]["element"]; }());

/***/ })

/******/ });
//# sourceMappingURL=index.js.map