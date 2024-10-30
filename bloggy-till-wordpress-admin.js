var BloggyTWP = new Object;
BloggyTWP = {
	
	addAccount: function() {
		var id = (BloggyTWP_no_accounts+1);
		
		var html =
			'<div id="BloggyTWP-account-'+ id +'" class="BloggyTWP-account">'+
				'<table class="form-table">'+
					'<tr valign="top">'+
						'<th scope="row"><label for="BloggyTWP_account_name_'+ id +'">Kontonamn</label></th>'+
						'<td>'+
							'<input '+ 
								'name="BloggyTWP_account_name['+ id +']" '+ 
								'type="text" '+
								'id="BloggyTWP_account_name_'+ id +'" '+ 
								'value="" '+ 
								'class="large-text" '+ 
							'/>'+
						'</td>'+
					'</tr>'+
					'<tr valign="top">'+
						'<th scope="row"><label for="BloggyTWP_account_password_'+ id +'">L&ouml;senord</label></th>'+
						'<td>'+
							'<input '+ 
								'name="BloggyTWP_account_password['+ id +']" '+ 
								'type="password" '+
								'id="BloggyTWP_account_password_'+ id +'" '+ 
								'value="" '+ 
								'class="large-text" '+ 
							'/> '+
							'<span class="setting-description">(L&ouml;senordet sparas i klartext i databasen. '+
							'<a href="http://borjablogga.se/bloggy-till-wordpress/">L&auml;s mer om det h&auml;r.</a>)</span>'+
						'</td>'+
					'</tr>'+
					'<tr valign="top">'+
						'<th scope="row"><label for="BloggyTWP_account_active_'+ id +'">Aktiverat</label></th>'+
						'<td>'+
							'<input '+
								'name="BloggyTWP_account_active['+ id +']" '+ 
								'type="checkbox" '+
								'id="BloggyTWP_account_active_'+ id +'" '+ 
								'value="1" '+
								'checked="checked" '+
							'/> <label for="BloggyTWP_account_active_'+ id +'">Ja</label>'+
						'</td>'+
					'</tr>'+
				'</table>'+
			'</div>' 
		;
		
		if (BloggyTWP_no_accounts == 0) {
			$('BloggyTWP-accounts-container').update(html);
		}
		else {
			$('BloggyTWP-accounts-container').insert(html, { position:'bottom' });
		}
		
		BloggyTWP_no_accounts++;
	},
	
	toggleAdvanceSettings: function(type) {
		var elements = $('BloggyTWP-options').getElementsByClassName('BloggyTWP-advance-settings');
		for (i=0,end=elements.length; i<end; i++) {
			if (type == 0) {
				elements[i].hide();
			}
			else {
				elements[i].show();
			}
		}
		if (type == 0) {
			$('BloggyTWP-toggle-advance-settings-show').show();
			$('BloggyTWP-toggle-advance-settings-hide').hide();
		}
		else {
			$('BloggyTWP-toggle-advance-settings-show').hide();
			$('BloggyTWP-toggle-advance-settings-hide').show();
		}
	}
	
}