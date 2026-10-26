/* Tipping Comp front end: round dropdowns and an unsaved-changes reminder. */
(function () {
	'use strict';

	document.querySelectorAll('[data-tipc-nav]').forEach(function (select) {
		select.addEventListener('change', function () {
			if (select.value) {
				window.location.href = select.value;
			}
		});
	});

	document.querySelectorAll('.tipc-form').forEach(function (form) {
		var dirty = false;
		var save = form.querySelector('.tipc-save');

		form.addEventListener('change', function () {
			dirty = true;
			if (save) {
				save.classList.add('is-dirty');
			}
		});
		form.addEventListener('submit', function () {
			dirty = false;
		});
		window.addEventListener('beforeunload', function (e) {
			if (dirty) {
				e.preventDefault();
				e.returnValue = '';
			}
		});
	});
})();
