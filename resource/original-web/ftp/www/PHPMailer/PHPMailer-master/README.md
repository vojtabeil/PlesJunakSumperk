ind the latest version of 5.2 in the [5.2-stable branch](https://github.com/PHPMailer/PHPMailer/tree/5.2-stable). If you're using PHP 5.5 or later (which you should be), switch to the 6.x releases.

### Upgrading from 5.2
The biggest changes are that source files are now in the `src/` folder, and PHPMailer now declares the namespace `PHPMailer\PHPMailer`. This has several important effects – [read the upgrade guide](https://github.com/PHPMailer/PHPMailer/tree/master/UPGRADING.md) for more details.

### Minimal installation
While installing the entire package manually or with Composer is simple, convenient, and reliable, you may want to include only vital files in your project. At the very least you will need [src/PHPMailer.php](https://github.com/PHPMailer/PHPMailer/tree/master/src/PHPMailer.php). If you're using SMTP, you'll need [src/SMTP.php](https://github.com/PHPMailer/PHPMailer/tree/master/src/SMTP.php), and if you're using POP-before SMTP (*very* unlikely!), you'll need [src/POP3.php](https://github.com/PHPMailer/PHPMailer/tree/master/src/POP3.php). You can skip the [language](https://github.com/PHPMailer/PHPMailer/tree/master/language/) folder if you're not showing errors to users and can make do with English-only errors. If you're using XOAUTH2 you will need [src/OAuth.php](https://github.com/PHPMailer/PHPMailer/tree/master/src/OAuth.php) as well as the Composer dependencies for the services you wish to authenticate with. Really, it's much easier to use Composer!

## A Simple Example

```php
<?php
//Import PHPMailer classes into the global namespace
//These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

//Load Composer's autoloader
require 'vendor/autoload.php';

//Create an instance; passing `true` enables exceptions
$mail = new PHPMailer(true);

try {
    //Server settings
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;                      //Enable verbose debug output
    $mail->isSMTP();                                            //Send using SMTP
    $mail->Host       = 'smtp.example.com';                     //Set the SMTP server to send through
    $mail->SMTPAuth   = true;                                   //Enable SMTP authentication
    $mail->Username   = 'user@example.com';                     //SMTP username
    $mail->Password   = 'secret';                               //SMTP password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            //Enable implicit TLS encryption
    $mail->Port       = 465;                                    //TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

    //Recipients
    $mail->setFrom('from@example.com', 'Mailer');
    $mail->addAddress('joe@example.net', 'Joe User');     //Add a recipient
    $mail->addAddress('ellen@example.com');               //Name is optional
    $mail->addReplyTo('info@example.com', 'Information');
    $mail->addCC('cc@example.com');
    $mail->addBCC('bcc@example.com');

    //Attachments
    $mail->addAttachment('/var/tmp/file.tar.gz');         //Add attachments
    $mail->addAttachment('/tmp/image.jpg', 'new.jpg');    //Optional name

    //Content
    $mail->isHTML(true);                                  //Set email format to HTML
    $mail->Subject = 'Here is the subject';
    $mail->Body    = 'This is the HTML message body <b>in bold!</b>';
    $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

    $mail->send();
    echo 'Message has been sent';
} catch (Exception $e) {
    echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
}
```

You'll find plenty to play with in the [examples](https://github.com/PHPMailer/PHPMailer/tree/master/examples) folder, which covers many common scenarios including sending through Gmail, building contact forms, sending to mailing lists, and more.

If you are re-using the instance (e.g. when sending to a mailing list), you may need to clear the recipient list to avoid sending duplicate messages. See [the mailing list example](https://github.com/PHPMailer/PHPMailer/blob/master/examples/mailing_list.phps) for further guidance.

That's it. You should now be ready to use PHPMailer!

## Localization
PHPMailer defaults to English, but in the [language](https://github.com/PHPMailer/PHPMailer/tree/master/language/) folder, you'll find many translations for PHPMailer error messages that you may encounter. Their filenames contain [ISO 639-1](http://en.wikipedia.org/wiki/ISO_639-1) language code for the translations, for example `fr` for French. To specify a language, you need to tell PHPMailer which one to use, like this:

```php
//To load the French version
$mail->setLanguage('fr', '/optional/path/to/language/directory/');
```

We welcome corrections and new languages – if you're looking for corrections, run the [Language/TranslationCompletenessTest.php](https://github.com/PHPMailer/PHPMailer/blob/master/test/Language/TranslationCompletenessTest.php) script in the tests folder and it will show any missing translations.

## Documentation
Start reading at the [GitHub wiki](https://github.com/PHPMailer/PHPMailer/wiki). If you're having trouble, head for [the troubleshooting guide](https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting) as it's frequently updated.

Examples of how to use PHPMailer for common scenarios can be found in the [examples](https://github.com/PHPMailer/PHPMailer/tree/master/examples) folder. If you're looking for a good starting point, we recommend you start with [the Gmail example](https://github.com/PHPMailer/PHPMailer/tree/master/examples/gmail.phps).

To reduce PHPMailer's deployed code footprint, examples are not included if you load PHPMailer via Composer or via [GitHub's zip file download](https://github.com/PHPMailer/PHPMailer/archive/master.zip), so you'll need to either clone the git repository or use the above links to get to the examples directly.

Complete generated API documentation is [available online](https://phpmailer.github.io/PHPMailer/).

You can generate complete API-level documentation by running `phpdoc` in the top-level folder, and documentation will appear in the `docs` folder, though you'll need to have [PHPDocumentor](http://www.phpdoc.org) installed. You may find [the unit tests](https://github.com/PHPMailer/PHPMailer/blob/master/test/PHPMailerTest.php) a good reference for how to do various operations such as encryption.

If the documentation doesn't cover what you need, search the [many questions on Stack Overflow](http://stackoverflow.com/questions/tagged/phpmailer), and before you ask a question about "SMTP Error: Could not connect to SMTP host.", [read the troubleshooting guide](https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting).

## Tests
[PHPMailer tests](https://github.com/PHPMailer/PHPMailer/tree/master/test/) use PHPUnit 9, with [a polyfill](https://github.com/Yoast/PHPUnit-Polyfills) to let 9-style tests run on older PHPUnit and PHP versions.

[![Test status](https://github.com/PHPMailer/PHPMailer/workflows/Tests/badge.svg)](https://github.com/PHPMailer/PHPMailer/actions)

If this isn't passing, is there something you can do to help?

## Security
Please disclose any vulnerabilities found responsibly – report security issues to the maintainers privately.

See [SECURITY](https://github.com/PHPMailer/PHPMailer/tree/master/SECURITY.md) and [PHPMailer's security advisories on GitHub](https://github.com/PHPMailer/PHPMailer/security). 

## Contributing
Please submit bug reports, suggestions, and pull requests to the [GitHub issue tracker](https://github.com/PHPMailer/PHPMailer/issues).

We're particularly interested in fixing edge cases, expanding test coverage, and updating translations.

If you found a mistake in the docs, or want to add something, go ahead and amend the wiki – anyone can edit it.

If you have git clones from prior to the move to the PHPMailer GitHub organisation, you'll need to update any remote URLs referencing the old GitHub location with a command like this from within your clone:

```sh
git remote set-url upstream https://github.com/PHPMailer/PHPMailer.git
```

Please *don't* use the SourceForge or Google Code projects any more; they are obsolete and no longer maintained.

## Sponsorship
Development time and resources for PHPMailer are provided by [Smartmessages.net](https://info.smartmessages.net/), the world's only privacy-first email marketing system.

<a href="https://info.smartmessages.net/"><img src="https://www.smartmessages.net/img/smartmessages-logo.svg" width="550" alt="Smartmessages.net privacy-first email marketing logo"></a>

Donations are very welcome, whether in beer 🍺, T-shirts 👕, or cold, hard cash 💰. Sponsorship through GitHub is a simple and convenient way to say "thank you" to PHPMailer's maintainers and contributors – just click the "Sponsor" button [on the project page](https://github.com/PHPMailer/PHPMailer). If your company uses PHPMailer, consider taking part in Tidelift's enterprise support programme.

## PHPMailer For Enterprise

Available as part of the Tidelift Subscription.

The maintainers of PHPMailer and thousands of other packages are working with Tidelift to deliver commercial
support and maintenance for the open-source packages you use to build your applications. Save time, reduce risk, and
improve code health, while paying the maintainers of the exact packages you
use. [Learn more.](https://tidelift.com/subscription/pkg/packagist-phpmailer-phpmailer?utm_source=packagist-phpmailer-phpmailer&utm_medium=referral&utm_campaign=enterprise&utm_term=repo)

## Changelog
See [changelog](changelog.md).

## History
- PHPMailer was originally written in 2001 by Brent R. Matzelle as a [SourceForge project](http://sourceforge.net/projects/phpmailer/).
- [Marcus Bointon](https://github.com/Synchro) (`coolbru` on SF) and Andy Prevost (`codeworxtech`) took over the project in 2004.
- Became an Apache incubator project on Google Code in 2010, managed by Jim Jagielski.
- Marcus created [his fork on GitHub](https://github.com/Synchro/PHPMailer) in 2008.
- Jim and Marcus decide to join forces and use GitHub as the canonical and official repo for PHPMailer in 2013.
- PHPMailer moves to [the PHPMailer organisation](https://github.com/PHPMailer) on GitHub in 2013.

### What's changed since moving from SourceForge?
- Official successor to the SourceForge and Google Code projects.
- Test suite.
- Continuous integration with GitHub Actions.
- Composer support.
- Public development.
- Additional languages and language strings.
- CRAM-MD5 authentication support.
- Preserves full repo history of authors, commits, and branches from the original SourceForge project.
