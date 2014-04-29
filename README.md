salsify-to-4-tell
=================

_**NOTE: this project's main purpose is to represent a more generally useful pattern beyond specifically providing a workable integration between Salsify and 4-Tell.**_


[Salsify](http://www.salsify.com/) does not support publishing to every data format in the universe, so wouldn't it be great if there were some easy to stand up a service _for free_ that transforms data from Salsify and posts it directly to other services in exactly the formats they need?

This project shows how to quickly build and deploy such a service (again, _for free_) on [Heroku](http://www.heroku.com). An experienced developer should be able to knock this out in a day (depending on the complexity of the target format).

The specific service in this case is [4-Tell](http://www.4-tell.com/), but the pattern should be applicable to just about anything else to which you'd like to publish data from Salsify.

# Getting Started

Creating and standing up your own service is a fairly simple process that requires only some basic programming experience (in this case PHP, but the pattern illustrated by this project can be used in any language supported by Heroku).

## 1. Build the adapter to translate Salsify to the new format.

The first key step is to write some code that does the translation from Salsify JSON to whatever the target format is.

The good news here is that this project provides code that does a ton of the heavy lifting (specifically dealing with all the data coming from Salsify); the only real work that you'll have to do in most cases is produce the output file format. The included 4-Tell illustrates shows how to produce a simple XML file, and it's really not much code.

1. Fork this project using `git clone`.
2. Implement `Salsify_JsonStreamingParserListener`.
    * `Salsify4TellAdapter.php` is an example of this. It's job is to translate data from Salsify to your destination format (again, in this case it's 4-Tell-compatible XML). The interface itself is straightforward; your implementation will get events such as `product()` and `attribute()` when an attribute is parsed.
    * Note: you'll have to update references to `Salsify4TellAdapter.php` in other files if you change the name of the file, which you'll probably end up doing.
3. Update `adapter_worker.php`. This is the file that does the heavy lifting and references the adapter you built in step 2.
4. Update `adapter.worker` to accurately reflect the files required by your application.
    * Note: while the whole project will be uploaded to Heroku, only the files specified in `adapter.worker` will be uploaded to IronWorker (see below), which does the lion's share of the work. The reality is that Heroku only really needs: `SalsifyWebhookReceiver.php` and the adapter worker files!

## 2. Deploy the application to Heroku and IronWorker.

Now that you have the code written you'll need to host the app somewhere and make sure it works. This section should take you 30-60 minutes or so your first time through, mostly to get yourself familiar with all the moving pieces; it really is that straightforward.

If you haven't heard of it before, **[Heroku](http://www.heroku.com/)** is a Platform as a Service (PaaS) that lets you run managed applications in the Cloud. Heroku is essentially a management layer on top of Amazon Web Services (Heroku itself is a Salesforce company). Heroku (and PaaS services in general) takes many of the key difficulties in running an application off your plate; in fact, Salsify itself is hosted on Heroku!

**[IronWorker](http://www.iron.io/)** is what does the "real work". Heroku web threads are killed after 30 seconds, so you'll need something other than a web thread to do the real heavy lifting (an alternative action is to use worker threads in Heroku, but then you'd have to start paying money). IronWorker is a free addon to Heroku applications - or at least free for one worker, which is all we need in this case!

1. Create a new application on Heroku.
    * If you haven't already, you'll need to [signup](https://id.heroku.com/signup). It's free. You'll also need to install the [toolbelt](https://toolbelt.heroku.com/).
    * The key command to run in your application directory: `heroku git:remote -a <HEROKU APP NAME>`
2. Add support for [IronWorker](https://addons.heroku.com/marketplace/iron_worker) to your Heroku application (note: also free).
    * Locally don't skip the reation of an `iron.json` file, which specifies your account information for uploading your worker code.
3. Deploy your application to Heroku using Git ([good instructions here](https://devcenter.heroku.com/articles/git)).
    * This usually does it once you have the `git remote` set up: `git push heroku master`
4. Deploy your application to IronWorker.
    * This will do it: `iron_worker upload adapter`

## 3. Link Salsify to your shiny new application.

The final step here is to make sure that Salsify exports are sent to your new adapter application, and to do this we use **webhooks**.

1. Create a Channel in Salsify for the new adapter.
2. Under the **Product Feed** section of the Channel configuration you set the format as `JSON` and do _not_ check the `compress` box.
    * When you do the configuration, you should definitely set up a **Channel Workflow** to ensure that all products sent in the feed have all the data that your adapter will assume that they do. This can dramatically simplify the logic within in the adapter itself since it will require much less error checking if all products sent to it are essentiall complete.
3. Under the **Publication Notifications** section of the channel configuration, scroll down to the *Webhooks* section, check **Call Webhook Upon Successful Publication**, and enter the URL of your application (e.g. `http://salsify-to-4-tell.herokuapp.com/SalsifyWebhookReceiver.php` in our example).

## 4. Test the Integration

For this we recommend filtering the products going to the channel to just a handful.

1. In your project, run `heroku logs -t`. This will tail the Heroku log so that you can see if/when the webhook from Salsify hits it (and whether there are any errors when it does!).
2. Click **Publish** on the Salsify Channel.
3. Check the status of the IronWorker task (when logged in, click the **Tasks** tab).

At this point you'll have access to the errors that happen in Heroku or on IronWorker and should be able to track things down and iterate.

License
-------

[MIT License](http://mit-license.org/) (c) 2014 Salsify, Inc.