import { ElMessage, ElMessageBox } from 'element-plus';
import { api, errorMessage } from './api';

/** "This is me": we email a 6-digit code to the address on the team record, and only a correct code links it to the signed-in login. */
export async function linkMeWithCode( member, done ) {
	try {
		await ElMessageBox.confirm( `We will email a 6-digit code to ${ member.email }. Only someone who can open that inbox can finish linking this record to your login.`, 'Confirm it is you', { confirmButtonText: 'Email me the code', cancelButtonText: 'Cancel' } );
	} catch ( e ) {
		return;
	}
	let sent;
	try {
		sent = await api.post( `team/${ member.id }/link-me`, {} );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		return;
	}
	for ( ;; ) {
		let code;
		try {
			( { value: code } = await ElMessageBox.prompt( `Enter the code sent to ${ sent.to }. It works for 10 minutes.`, 'Enter your code', { confirmButtonText: 'Link my login', cancelButtonText: 'Cancel', inputPattern: /^\s*\d{6}\s*$/, inputErrorMessage: 'The code has 6 digits', inputPlaceholder: '123456' } ) );
		} catch ( e ) {
			return;
		}
		try {
			await api.post( `team/${ member.id }/link-me/verify`, { code } );
			ElMessage.success( 'Linked to your login.' );
			done && done();
			return;
		} catch ( e ) {
			ElMessage.error( errorMessage( e ) );
			if ( /expired|Too many/.test( errorMessage( e ) ) ) {
				return;
			}
		}
	}
}
