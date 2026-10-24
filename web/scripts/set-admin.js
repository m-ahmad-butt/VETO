const bcrypt = require('bcryptjs');
const mongoose = require('mongoose');

(async () => {
  await mongoose.connect(process.env.MONGO_URI || 'mongodb://mongodb:27017/authdb');
  const User = mongoose.connection.collection('users');
  const email = 'm.ahmad.software.engineer@gmail.com';
  const password = await bcrypt.hash('123123123', 10);

  const byEmail = await User.findOne({ email });
  const byAdmin = await User.findOne({ role: 'ADMIN' });
  console.log('byEmail', byEmail && { id: String(byEmail._id), email: byEmail.email, role: byEmail.role });
  console.log('byAdmin', byAdmin && { id: String(byAdmin._id), email: byAdmin.email, role: byAdmin.role });

  if (byEmail) {
    await User.updateOne(
      { _id: byEmail._id },
      { $set: { password, role: 'ADMIN', isVerified: true, fullName: byEmail.fullName || 'Ahmad' } }
    );
    if (byAdmin && String(byAdmin._id) !== String(byEmail._id)) {
      await User.deleteOne({ _id: byAdmin._id });
      console.log('removed legacy admin', byAdmin.email);
    }
    console.log('updated existing email user to ADMIN');
  } else if (byAdmin) {
    await User.updateOne(
      { _id: byAdmin._id },
      { $set: { email, password, role: 'ADMIN', isVerified: true, fullName: byAdmin.fullName || 'Ahmad' } }
    );
    console.log('updated legacy admin email');
  } else {
    await User.insertOne({
      email,
      password,
      role: 'ADMIN',
      fullName: 'Ahmad',
      isVerified: true,
      createdAt: new Date(),
      updatedAt: new Date(),
    });
    console.log('inserted admin');
  }

  await mongoose.disconnect();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
