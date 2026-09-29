var CALENDAR_ID = '5cd2a9937e542566f2fd0a0ebda0d82bbbb7416ae05017cc66b476ea4c7a91cd@group.calendar.google.com';
var EVENT_PROPERTY_PREFIX = 'utmklride_event_';
var MALAYSIA_TIME_OFFSET = '+08:00';

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      throw new Error('Empty request body.');
    }

    var data = JSON.parse(e.postData.contents);
    var action = String(data.action || 'create').toLowerCase();
    var calendar = CalendarApp.getCalendarById(CALENDAR_ID);

    if (!calendar) {
      throw new Error('Calendar not found or this script has no access to it.');
    }

    var result;

    if (action === 'create') {
      result = createRideEvent(calendar, data);
    } else if (action === 'cancel') {
      result = cancelRideEvent(calendar, data);
    } else {
      throw new Error('Unsupported action: ' + action);
    }

    console.log('UTMKL_RESULT ' + JSON.stringify(result));

    // Only show "Completed" in Apps Script when the requested action really
    // completed. Previously not_found/manual_required were returned normally,
    // so Google displayed Completed even though no event had been deleted.
    if (result.status !== 'success') {
      throw new Error(
        'Calendar action did not complete: ' +
        String(result.result || result.status) +
        ' (' + String(result.booking_id || 'unknown booking') + ')'
      );
    }

    return jsonResponse(result);
  } catch (error) {
    console.error('UTMKL_ERROR ' + error.toString());
    throw error;
  }
}

function createRideEvent(calendar, data) {
  var bookingId = normalizeBookingId(data.booking_id);
  var startTime = buildStartTime(data);
  var endTime = new Date(startTime.getTime() + (60 * 60 * 1000));
  var properties = PropertiesService.getScriptProperties();
  var propertyKey = eventPropertyKey(bookingId);
  var storedEventId = properties.getProperty(propertyKey);

  // The create action is idempotent: refreshing a successful booking page will
  // not create a second Calendar event for the same booking reference.
  if (storedEventId) {
    var storedEvent = calendar.getEventById(storedEventId);
    if (storedEvent) {
      return {
        status: 'success',
        action: 'create',
        result: 'already_exists',
        booking_id: bookingId
      };
    }
    properties.deleteProperty(propertyKey);
  }

  // During CREATE, only an event carrying this exact booking reference may be
  // reused. Never reuse a legacy event merely because the passenger and route
  // look the same; that can belong to a different booking.
  var recovered = findMatchingEvent(calendar, data, bookingId, false);
  if (recovered.ambiguous) {
    return {
      status: 'manual_required',
      action: 'create',
      result: 'ambiguous_existing_events',
      booking_id: bookingId
    };
  }

  if (recovered.event) {
    properties.setProperty(propertyKey, recovered.event.getId());
    return {
      status: 'success',
      action: 'create',
      result: 'existing_event_linked',
      booking_id: bookingId
    };
  }

  var title = '🚕 [' + bookingId + '] Ride: ' + requiredText(data.nama, 'nama');
  var description = [
    'Booking ID: ' + bookingId,
    'No Tel: ' + requiredText(data.telefon, 'telefon'),
    'Pick-up: ' + requiredText(data.pickup, 'pickup'),
    'Drop-off: ' + requiredText(data.dropoff, 'dropoff')
  ].join('\n');

  var event = calendar.createEvent(title, startTime, endTime, {
    description: description,
    location: requiredText(data.pickup, 'pickup')
  });

  event.addPopupReminder(15);
  properties.setProperty(propertyKey, event.getId());

  return {
    status: 'success',
    action: 'create',
    result: 'created',
    booking_id: bookingId
  };
}

function cancelRideEvent(calendar, data) {
  var bookingId = normalizeBookingId(data.booking_id);
  var properties = PropertiesService.getScriptProperties();
  var propertyKey = eventPropertyKey(bookingId);
  var storedEventId = properties.getProperty(propertyKey);
  var event = null;
  var matchMethod = 'stored_event_id';

  if (storedEventId) {
    event = calendar.getEventById(storedEventId);
    if (!event) {
      properties.deleteProperty(propertyKey);
    }
  }

  // Safe fallback for events created by the old script before event IDs were
  // stored. It only deletes when exactly one event matches the booking data.
  if (!event) {
    var recovered = findMatchingEvent(calendar, data, bookingId, true);

    if (recovered.ambiguous) {
      return {
        status: 'manual_required',
        action: 'cancel',
        result: 'ambiguous_events',
        booking_id: bookingId
      };
    }

    event = recovered.event;
    matchMethod = 'exact_booking_details';
  }

  if (!event) {
    return {
      status: 'not_found',
      action: 'cancel',
      result: 'event_not_found',
      booking_id: bookingId
    };
  }

  event.deleteEvent();
  properties.deleteProperty(propertyKey);

  return {
    status: 'success',
    action: 'cancel',
    result: 'deleted',
    matched_by: matchMethod,
    booking_id: bookingId
  };
}

function findMatchingEvent(calendar, data, bookingId, allowLegacyMatch) {
  var startTime;

  try {
    startTime = buildStartTime(data);
  } catch (error) {
    return { event: null, ambiguous: false };
  }

  // Old script versions relied on the Apps Script project time zone. Search a
  // wider window so a legacy event can still be found after a time-zone change.
  var windowStart = new Date(startTime.getTime() - (24 * 60 * 60 * 1000));
  var windowEnd = new Date(startTime.getTime() + (24 * 60 * 60 * 1000));
  var events = calendar.getEvents(windowStart, windowEnd);
  var expectedOldTitle = normalizeText('🚕 Ride: ' + String(data.nama || ''));
  var expectedPhone = normalizeText('No Tel: ' + String(data.telefon || ''));
  var expectedPickup = normalizeText('Pick-up: ' + String(data.pickup || ''));
  var expectedDropoff = normalizeText('Drop-off: ' + String(data.dropoff || ''));
  var bookingNeedle = normalizeText(bookingId);
  var bookingIdMatches = events.filter(function (event) {
    var title = normalizeText(event.getTitle());
    var description = normalizeText(event.getDescription());
    return (title + ' ' + description).indexOf(bookingNeedle) !== -1;
  });

  console.log(
    'UTMKL_SCAN booking=' + bookingId +
    ' total=' + events.length +
    ' id_matches=' + bookingIdMatches.length
  );

  if (bookingIdMatches.length === 1) {
    return { event: bookingIdMatches[0], ambiguous: false };
  }

  if (bookingIdMatches.length > 1) {
    return { event: null, ambiguous: true };
  }

  if (!allowLegacyMatch) {
    console.log(
      'UTMKL_SCAN booking=' + bookingId +
      ' legacy_search=skipped_for_create'
    );
    return { event: null, ambiguous: false };
  }

  var legacyMatches = events.filter(function (event) {
    var title = normalizeText(event.getTitle());
    var description = normalizeText(event.getDescription());

    // Legacy events did not contain the booking reference. Require every
    // original identifying field, but do not require an exact start time.
    // This handles events created under the wrong Apps Script time zone.
    return title === expectedOldTitle &&
      description.indexOf(expectedPhone) !== -1 &&
      description.indexOf(expectedPickup) !== -1 &&
      description.indexOf(expectedDropoff) !== -1;
  });

  console.log(
    'UTMKL_SCAN booking=' + bookingId +
    ' legacy_matches=' + legacyMatches.length
  );

  return {
    event: legacyMatches.length === 1 ? legacyMatches[0] : null,
    ambiguous: legacyMatches.length > 1
  };
}

function buildStartTime(data) {
  var date = requiredText(data.tarikh, 'tarikh');
  var time = requiredText(data.masa, 'masa');

  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time)) {
    throw new Error('Invalid date or time format.');
  }

  var startTime = new Date(date + 'T' + time + ':00' + MALAYSIA_TIME_OFFSET);

  if (isNaN(startTime.getTime())) {
    throw new Error('Invalid booking date or time.');
  }

  return startTime;
}

function normalizeBookingId(value) {
  var bookingId = String(value || '').trim().toUpperCase();

  if (!/^UTM-\d+$/.test(bookingId)) {
    throw new Error('Invalid booking ID.');
  }

  return bookingId;
}

function eventPropertyKey(bookingId) {
  return EVENT_PROPERTY_PREFIX + bookingId;
}

function requiredText(value, fieldName) {
  var text = String(value || '').trim();

  if (!text) {
    throw new Error('Missing field: ' + fieldName);
  }

  return text;
}

function normalizeText(value) {
  return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function jsonResponse(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}
