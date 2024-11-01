jQuery(function($){

	$('.misha_mch_stop_resync').click(function(){

		if ( confirm("Are you sure you want to cancel?") ) {
		  return true;
		} else {
		  return false;
		}

	});

	/**
	 * Role / Status dropdown change
	 */
	$( '.misha_mch_roles_dd' ).change(function(){
		var roleDD = $(this),
				role = roleDD.val();

		// let's make Add button disabled or enabled
		if( role === '' ) roleDD.next().prop('disabled',true); else roleDD.next().prop('disabled',false);

	});

	/**
	 * Add Rule button
	 */
	$('.misha_mch_add_rule').click(function(){

		var button = $(this),
				roleDD = button.prev(),
				roleOrStatus = roleDD.val();

		if( roleOrStatus !== '' ) {

			var roleOrStatusName = roleDD.children('option:selected').data('role-label'),
					tr = '<tr data-for-role="' + roleOrStatus + '">'
					+ '<td><input type="checkbox" class="misha_mch_checkbox"></td><td>' + roleOrStatusName + '</td>'
					+ '<td><select id="misha_mch_select_' + roleOrStatus + '" class="misha_mch_list_select" name="misha_mch_list_for_[' + roleOrStatus + ']">' + dropdown_lists + '</select></td></tr>',
					tbody = button.parent().parent().parent().prev();

			tbody.find('.misha_mch_no_rules').hide();
			tbody.append( tr );
			roleDD.children('option:selected').prop('disabled',true);
			roleDD.val('');

			// if( roleDD.children().length === 1 ) {
			// 	button.hide();
			// 	roleDD.hide();
			// }

			button.prop('disabled',true);

			nothingChanged = false;

		}

		return false;
	});

	/**
	 * Select ALL for table checkboxes
	 */
	$('.misha_mch_select_all').click(function (e) {
    $(this).closest('table').find('td input:checkbox').prop('checked', this.checked);
	});

	/**
	 * Enable the Delete Selected button
	 */
	$('body').on('change', '.misha_mch_checkbox', function() {
		var table = $(this).closest('table'),
				disable_button = true,
				remove_button = table.find('.misha_mch_remove_rule');

		table.find('tbody tr td input:checkbox').each(function( i ){
			if(this.checked) {
	    	disable_button = false;
	    }
		});

		if( disable_button == false ) {
			remove_button.prop('disabled',false);
		} else {
			remove_button.prop('disabled',true);
		}
	});

	/**
	 * Remove Rule button
	 */
	$('.misha_mch_remove_rule').click(function(){

		var table = $(this).closest('table'),
				roleDD = $(this).prev().prev();

		table.find('tbody tr td input:checkbox:checked').each(function(i){
			var tr = $(this).parent().parent(),
					role = tr.data('for-role');

			//enable in select
			roleDD.find('option[value="' + role + '"]').prop('disabled',false);
			//remove
			tr.remove();
		});

		if( table.find('tbody tr').length === 1 ) {
			table.find('.misha_mch_no_rules').show();
		}

		$(this).prop('disabled',true);

		nothingChanged = false;

		return false;
	});

	/**
	 * Start resync double check
	 */
	$( '.misha_mch_start_resync_button').click(function(){

		if( nothingChanged == false ) {
			if ( confirm("You made some changes on this page, but didn't save. Do you want to continue?") ) {
			  return true;
			} else {
			  return false;
			}
		}

	});

});
